<?php

namespace SITC\Sinchimport\Model\Import;

class CustomCatalogVisibility extends AbstractImportSection {
    const CHUNK_SIZE = 1000;
    const RESTRICTED_THRESHOLD = 1000;
    const ATTRIBUTE_NAME = "sinch_restrict";
    const LOG_PREFIX = "CustomCatalog: ";

    private $tmpTable = "sinch_custom_catalog_tmp";
    private $finalRulesTable = "sinch_custom_catalog_final_tmp";

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

    /** @var string */
    private $cpeTable;
    /** @var string */
    private $productMappingTable;


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

    /**
     * @var array $productIsWhitelist Product ID => Whitelist/Blacklist mode (true = whitelist)
     */
    private $productIsWhitelist = [];

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
        $this->productMappingTable = $this->getTableName('sinch_products_mapping');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/sinch_custom_catalog.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
        $this->output = $output;
    }

    private function cleanupTempTables()
    {
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->tmpTable}");
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->finalRulesTable}");
    }

    private function createTempTable()
    {
        $this->cleanupTempTables();
        $this->getConnection()->query("SET SESSION group_concat_max_len = 102400");
        $this->getConnection()->query(
            "CREATE TABLE {$this->tmpTable} (
                rule_id int(11) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
                product_id int(11) unsigned NOT NULL COMMENT 'Sinch Product ID',
                group_id int(11) unsigned NOT NULL COMMENT 'Group ID',
                INDEX (product_id)
            )"
        );
        $this->getConnection()->query(
            "CREATE TABLE {$this->finalRulesTable} (
                product_id int(11) unsigned NOT NULL PRIMARY KEY COMMENT 'Product Entity ID',
                rule varchar(255) NOT NULL
            )"
        );
    }

    public function parse($stockPriceFile, $customerGroupPriceFile)
    {
        $parseStart = $this->microtime_float();

        $this->createTempTable();
        $this->checkProductModes($stockPriceFile);
        $this->processGroupPrices($customerGroupPriceFile);
        $this->buildFinalRules();
        //$this->cleanupTempTables();

        $elapsed = number_format($this->microtime_float() - $parseStart, 2);
        $this->log("Imported {$this->restrictCount} restrictions in {$elapsed} seconds");
    }

    /**
     * @param string $value String to check for emptiness/whitespace
     * @return bool True if empty or whitespace
     */
    private function isEmptyOrWhitespace($value)
    {
        return empty($value) || empty(trim($value));
    }

    /**
     * @param string $stockPriceFile The path to StockAndPrices.csv
     */
    private function checkProductModes($stockPriceFile)
    {
        $this->log("Checking product modes");
        $this->stockPriceCsv->openIter($stockPriceFile);
        $this->stockPriceCsv->take(1); //Discard first row

        //ProductID|Stock|Price|Cost|DistributorID
        while($toProcess = $this->stockPriceCsv->take(self::CHUNK_SIZE)) {
            foreach($toProcess as $row) {
                //Check if Price and Cost columns are empty
                $this->productIsWhitelist[$row[0]] = $this->isEmptyOrWhitespace($row[2]) && $this->isEmptyOrWhitespace($row[3]);
            }
        }
        $this->stockPriceCsv->closeIter();

        $numWhitelist = count(array_filter($this->productIsWhitelist, function($v) { return $v == true; }));
        $numBlacklist = count($this->productIsWhitelist) - $numWhitelist;
        $this->log("{$numWhitelist} products in whitelist mode");
        $this->log("{$numBlacklist} products in blacklist mode");
    }

    /**
     * @var string $customerGroupPriceFile The path to CustomerGroupPrices.csv
     */
    private function processGroupPrices($customerGroupPriceFile)
    {
        $this->log("Processing group prices");
        $this->groupPriceCsv->openIter($customerGroupPriceFile);
        $this->groupPriceCsv->take(1); //Discard first row

        //CustomerGroupID|ProductID|PriceTypeID|Price
        while($toProcess = $this->groupPriceCsv->take(self::CHUNK_SIZE)) {
            $rulesForInsertion = [];
            foreach($toProcess as $row) {
                //Whitelist/blacklist logic
                $whitelist = $this->productIsWhitelist[$row[1]];
                $noPrice = $this->isEmptyOrWhitespace($row[3]);
                //Whitelist mode (Non-empty group price means visible) || Blacklist mode (Empty group price means not visible)
                if(($whitelist & !$noPrice) || (!$whitelist && $noPrice)) {
                    $rulesForInsertion[] = [
                        "product_id" => $row[1],
                        "group_id" => $row[0]
                    ];
                }
            }
            $this->getConnection()->insertOnDuplicate($this->tmpTable, $rulesForInsertion);
        }
        $this->groupPriceCsv->closeIter();
    }

    private function buildFinalRules()
    {
        $this->log("Building final ruleset");

        //Prepare the final rules ready for attributes
        $this->getConnection()->query(
        "INSERT INTO {$this->finalRulesTable} (product_id, rule)
            SELECT spm.entity_id, GROUP_CONCAT(group_id ORDER BY group_id ASC) FROM {$this->tmpTable} cct
                INNER JOIN {$this->productMappingTable} spm
                ON cct.product_id = spm.sinch_product_id
                GROUP BY product_id"
        );

        $whitelistProducts = $this->sinchToEntityIds(array_keys(array_filter($this->productIsWhitelist, function($v) { return $v == 1; })));
        $blacklistProducts = $this->sinchToEntityIds(array_keys(array_filter($this->productIsWhitelist, function($v) { return $v == 0; })));

        if(count($whitelistProducts) > 0) {
            $placeholders = implode(',', array_fill(0, count($whitelistProducts), '?'));
            $whitelistValues = $this->getConnection()->fetchCol(
                "SELECT DISTINCT rule FROM {$this->finalRulesTable} WHERE product_id IN ({$placeholders})",
                $whitelistProducts
            );
            $whitelistValCount = count($whitelistValues);
            $this->log("{$whitelistValCount} distinct whitelist values");

            foreach($whitelistValues as $whitelistValue) {
                $products = $this->getConnection()->fetchCol(
                    "SELECT product_id FROM {$this->finalRulesTable} WHERE rule = :value",
                    [":value" => $whitelistValue]
                );

                if(!empty($products)) {
                    $this->log("Applying whitelist values");
                    $this->massProdValues->updateAttributes(
                        $products,
                        [self::ATTRIBUTE_NAME => $whitelistValue],
                        0 //store id (dummy value as they're global attributes)
                    );
                    $this->restrictCount += count($products);
                }
            }
            $this->log("Whitelist values have been applied");
        }

        if(count($blacklistProducts) > 0) {
            $placeholders = implode(',', array_fill(0, count($blacklistProducts), '?'));
            $blacklistValues = $this->getConnection()->fetchCol(
                "SELECT DISTINCT rule FROM {$this->finalRulesTable} WHERE product_id IN ({$placeholders})",
                $blacklistProducts
            );
            $blacklistValCount = count($blacklistValues);
            $this->log("{$blacklistValCount} distinct blacklist values");

            foreach($blacklistValues as $blacklistValue) {
                $products = $this->getConnection()->fetchCol(
                    "SELECT product_id FROM {$this->finalRulesTable} WHERE rule = :value",
                    [":value" => $blacklistValue]
                );

                if(!empty($products)) {
                    $this->massProdValues->updateAttributes(
                        $products,
                        [self::ATTRIBUTE_NAME => "!" . $blacklistValue],
                        0 //store id (dummy value as they're global attributes)
                    );
                    $this->restrictCount += count($products);
                }
            }
            $this->log("Blacklist values have been applied");
        }

        //Clear the sinch_restrict value for products that no longer have a rule (but not non-sinch products)
        $noValueSinchProds = $this->getConnection()->fetchCol(
            "SELECT entity_id FROM {$this->cpeTable}
                WHERE entity_id NOT IN (SELECT product_id FROM {$this->finalRulesTable})
                AND store_product_id IS NOT NULL"
        );

        $numProds = count($noValueSinchProds);
        if($numProds > 0) {
            $this->log("Clearing attribute value for {$numProds} products");
            $this->massProdValues->updateAttributes(
                $noValueSinchProds,
                [self::ATTRIBUTE_NAME => ""],
                0
            );
        }
    }

    private function sinchToEntityIds($sinch_prod_ids)
    {
        if(empty($sinch_prod_ids)) return [];
        $placeholders = implode(',', array_fill(0, count($sinch_prod_ids), '?'));
        return $this->getConnection()->fetchCol(
            "SELECT entity_id FROM {$this->productMappingTable} WHERE sinch_product_id IN ($placeholders)",
            $sinch_prod_ids
        );
    }

    private function log($message)
    {
        $this->logger->info(self::LOG_PREFIX . $message);
        $this->output->writeln(self::LOG_PREFIX . $message);
    }
}