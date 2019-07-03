<?php

namespace SITC\Sinchimport\Model\Import;

class CustomCatalogVisibility extends AbstractImportSection {
    const CHUNK_SIZE = 500;
    const RESTRICTED_THRESHOLD = 1000;
    const ATTRIBUTE_NAME = "sinch_restrict";
    const LOG_PREFIX = "CustomCatalog: ";

    private $tmpTable = "sinch_custom_catalog_tmp";

    /**
     * @var \SITC\Sinchimport\Util\CsvIterator $stockPriceCsv
     */
    private $stockPriceCsv;
    /**
     * @var \SITC\Sinchimport\Util\CsvIterator $groupPriceCsv
     */
    private $groupPriceCsv;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Action $massProdValues
     */
    private $massProdValues;

    /**
     * @var string
     */
    private $cpeTable;

    /**
     * @var \Zend\Log\Logger $logger
     */
    private $logger;
    /**
     * @var \Symfony\Component\Console\Output\ConsoleOutput $output
     */
    private $output;

    /**
     * @var int
     */
    private $restrictCount = 0;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Util\CsvIterator $csv,
        \Magento\Catalog\Model\ResourceModel\Product\Action $massProdValues,
        \Symfony\Component\Console\Output\ConsoleOutput $output
    ){
        parent::__construct($resourceConn);
        $this->stockPriceCsv = $csv->setLineLength(256)->setDelimiter("|");
        $this->groupPriceCsv = clone $this->stockPriceCsv;
        $this->massProdValues = $massProdValues;

        $this->cpeTable = $this->getTableName('catalog_product_entity');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/sinch_custom_catalog.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
        $this->output = $output;
    }

    private function createTempTable()
    {
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->tmpTable}");
        $this->getConnection()->query(
            "CREATE TABLE {$this->tmpTable} (
                product_id int(10) unsigned NOT NULL PRIMARY KEY COMMENT 'Magento Product ID',
                value varchar(255) NOT NULL COMMENT 'Rules',
                FOREIGN KEY (product_id) REFERENCES {$this->cpeTable} (entity_id) ON DELETE CASCADE ON UPDATE CASCADE
            )"
        );
    }

    public function parse($stockPriceFile, $customerGroupPriceFile)
    {
        $parseStart = $this->microtime_float();

        $this->createTempTable();
        //Build inverse rules first as forward rules are more restrictive (and can safely replace inverse rules if applicable)
        $this->buildInverseRules($customerGroupPriceFile); 
        $this->buildForwardRules($stockPriceFile, $customerGroupPriceFile);
        $this->setAttributeValues();

        $elapsed = number_format($this->microtime_float() - $parseStart, 2);
        $this->log("Imported {$this->restrictCount} restrictions in {$elapsed} seconds");
    }

    private function buildForwardRules($stockPriceFile, $customerGroupPriceFile)
    {
        $this->log("Processing forward rules");
        $this->stockPriceCsv->openIter($stockPriceFile);
        $this->stockPriceCsv->take(1); //Discard first row

        $restricted = [];
        //ProductID|Stock|Price|Cost|DistributorID
        while($toProcess = $this->stockPriceCsv->take(self::CHUNK_SIZE)) {
            foreach($toProcess as $row){
                //Check if Price and Cost columns are empty
                if(empty($row[2]) && empty($row[3])){
                    //Store the Product ID
                    $this->logger->info("Found restricted product: {$row[0]}");
                    $restricted[] = $row[0];
                }
            }
            if(count($restricted) >= self::RESTRICTED_THRESHOLD){
                $this->findAccountRestrictions($customerGroupPriceFile, $restricted);
                $restricted = [];
            }
        }
        $this->findAccountRestrictions($customerGroupPriceFile, $restricted);
        $this->stockPriceCsv->closeIter();
    }

    private function buildInverseRules($customerGroupPriceFile)
    {
        $this->log("Processing inverse rules");
        $this->groupPriceCsv->openIter($customerGroupPriceFile);
        $this->groupPriceCsv->take(1);

        $inverse = [];
        //CustomerGroupID|ProductID|PriceTypeID|Price
        while($toProcess = $this->groupPriceCsv->take(self::CHUNK_SIZE)) {
            foreach($toProcess as $row){
                if(empty(trim($row[3]))) {
                    $inverse[$row[1]][] = "!" . $row[0];
                }
            }
            if(count($inverse) >= self::RESTRICTED_THRESHOLD){
                $this->applyAccountRestrictions($inverse);
                $inverse = [];
            }
        }
        $this->applyAccountRestrictions($inverse);

        $this->groupPriceCsv->closeIter();
    }

    private function findAccountRestrictions($customerGroupPriceFile, $restricted)
    {
        $this->log("Finding matching account groups for " . count($restricted) . " products");
        //Holds a mapping of Sinch product ID -> [Account Group ID]
        $mapping = [];

        $this->groupPriceCsv->openIter($customerGroupPriceFile);
        $this->groupPriceCsv->take(1);

        while($groupPriceChunk = $this->groupPriceCsv->take(self::CHUNK_SIZE)){
            //CustomerGroupID|ProductID|PriceTypeID|Price
            foreach($groupPriceChunk as $groupPrice){
                if(in_array($groupPrice[1], $restricted)){
                    //Group price matches a restricted product
                    $prefix = empty(trim($groupPrice[3])) ? "!" : "";
                    $mapping[$groupPrice[1]][] = $prefix . $groupPrice[0];
                }
            }
        }
        $this->groupPriceCsv->closeIter();
        $this->applyAccountRestrictions($mapping);
    }

    private function applyAccountRestrictions($mapping)
    {
        $this->logger->info("Processing restrictions for " . count($mapping) . " products");
        $sinchEntityPairs = $this->sinchToEntityIds(array_keys($mapping));

        $prepared = [];
        foreach($sinchEntityPairs as $pair){
            //Check for existing record in the temp table with value for this product and merge if yes
            $existing = $this->getConnection()->fetchOne("SELECT value FROM {$this->tmpTable} WHERE product_id = ?", $pair['entity_id']);
            if(!empty($existing)) {
                $restrictArr = array_merge(
                    explode(",", $existing),
                    $mapping[$pair['sinch_product_id']]
                );
            } else {
                $restrictArr = $mapping[$pair['sinch_product_id']];
            }

            $prepared[] = [
                'product_id' => $pair['entity_id'],
                'value' => implode(",", $restrictArr)
            ];
            $this->restrictCount += 1;
        }

        if(count($prepared) > 0){
            $this->getConnection()->insertOnDuplicate(
                $this->tmpTable,
                $prepared,
                ["value"]
            );
        }
    }

    private function setAttributeValues()
    {
        $this->log("Updating attributes");
        $values = $this->getConnection()->fetchCol("SELECT DISTINCT value FROM {$this->tmpTable}");
        $numValues = count($values);
        $this->log("{$numValues} distinct values to update attributes for");
        $i = 1;
        foreach($values as $value){
            $productIds = $this->getConnection()->fetchCol("SELECT product_id FROM {$this->tmpTable} WHERE value = ?", $value);
            $numProds = count($productIds);
            $this->log("({$i}/{$numValues}) Updating attribute for {$numProds} products to: {$value}");
            $this->massProdValues->updateAttributes(
                $productIds,
                [self::ATTRIBUTE_NAME => $value],
                0 //store id (dummy value as they're global attributes)
            );
            $i += 1;
        }

        //Clear the sinch_restrict value for products that no longer have a rule (but not non-sinch products)
        $noValueSinchProds = $this->getConnection()->fetchCol(
            "SELECT entity_id FROM {$this->cpeTable}
                WHERE entity_id NOT IN (SELECT product_id FROM {$this->tmpTable})
                AND store_product_id IS NOT NULL"
        );

        $numProds = count($noValueSinchProds);
        $this->log("Clearing attribute value for {$numProds} products");
        $this->massProdValues->updateAttributes(
            $noValueSinchProds,
            [self::ATTRIBUTE_NAME => ""],
            0
        );
    }

    private function sinchToEntityIds($sinch_prod_ids)
    {
        if(empty($sinch_prod_ids)) return [];
        $placeholders = implode(',', array_fill(0, count($sinch_prod_ids), '?'));
        $entIdQuery = $this->getConnection()->prepare(
            "SELECT sinch_product_id, entity_id FROM {$this->cpeTable} WHERE sinch_product_id IN ($placeholders)"
        );
        $entIdQuery->execute($sinch_prod_ids);
        return $entIdQuery->fetchAll(\PDO::FETCH_ASSOC, 0);
    }

    private function log($message)
    {
        $this->logger->info(self::LOG_PREFIX . $message);
        $this->output->writeln(self::LOG_PREFIX . $message);
    }
}