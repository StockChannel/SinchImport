<?php

namespace SITC\Sinchimport\Model\Import;

class CustomCatalogVisibility {
    const CHUNK_SIZE = 500;
    const RESTRICTED_THRESHOLD = 100;
    const ATTRIBUTE_NAME = "sinch_restrict";

    private $stockPriceCsv;
    private $groupPriceCsv;

    private $attributeManagement;
    private $massProdValues;

    private $cpeTable;

    private $logger;

    public function __construct(
        \SITC\Sinchimport\Util\CsvIterator $csv,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Catalog\Api\ProductAttributeManagementInterface $attributeManagement,
        \Magento\Catalog\Model\ResourceModel\Product\Action $massProdValues
    ){
        $this->stockPriceCsv = $csv->setLineLength(256)->setDelimiter("|");
        $this->groupPriceCsv = clone $this->stockPriceCsv;
        $this->resourceConn = $resourceConn;
        $this->attributeManagement = $attributeManagement;
        $this->massProdValues = $massProdValues;

        $this->cpeTable = $this->resourceConn->getTableName('catalog_product_entity');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/sinch_custom_catalog.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
    }

    
    public function parse($stockPriceFile, $customerGroupPriceFile)
    {
        $this->stockPriceCsv->openIter($stockPriceFile);
        $this->stockPriceCsv->take(1); //Discard first row

        $restricted = [];
        //ProductID|Stock|Price|Cost|DistributorID
        while($toProcess = $this->stockPriceCsv->take(self::CHUNK_SIZE)) {
            foreach($toProcess as $row){
                //Check if Price and Cost columns are empty
                if(empty($row[2]) && empty($row[3])){
                    //Store the Product ID
                    $restricted[] = $row[0];
                }
            }
            if(count($restricted) > self::RESTRICTED_THRESHOLD){
                $this->findAccountRestrictions($customerGroupPriceFile, $restricted);
                $restricted = [];
            }
        }
        $this->findAccountRestrictions($customerGroupPriceFile, $restricted);
        $this->csv->closeIter();
    }

    private function findAccountRestrictions($customerGroupPriceFile, $restricted)
    {
        //Holds a mapping of Sinch product ID -> [Account Group ID]
        $mapping = [];

        $this->groupPriceCsv->openIter($customerGroupPriceFile);
        $this->groupPriceCsv->take(1);

        while($groupPriceChunk = $this->groupPriceCsv->take(self::CHUNK_SIZE)){
            //CustomerGroupID|ProductID|PriceTypeID|Price
            foreach($groupPriceChunk as $groupPrice){
                if(in_array($groupPrice[1], $restricted)){
                    //Group price matches a restricted product
                    $mapping[$groupPrice[0]][] = $groupPrice[1];
                }
            }
        }
        $this->applyAccountRestrictions($mapping);
    }

    private function applyAccountRestrictions($mapping)
    {
        $sinchEntityPairs = $this->sinchToEntityIds(array_keys($mapping));
        foreach($sinchEntityPairs as $pair){
            $restrictValue = implode(",", $mapping[$pair['sinch_product_id']]);

            $this->logger->info("{$pair['sinch_product_id']} => {$restrictValue}");

            $this->massProdValues->updateAttributes(
                [$pair['entity_id']],
                [self::ATTRIBUTE_NAME => $restrictValue],
                0 //store id (dummy value as they're global attributes)
            );
        }
    }

    private function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }

    private function sinchToEntityIds($sinch_prod_ids)
    {
        $placeholders = implode(',', array_fill(0, count($sinch_prod_ids), '?'));
        $entIdQuery = $this->getConnection()->prepare(
            "SELECT sinch_product_id, entity_id FROM {$this->cpeTable} WHERE sinch_product_id IN ($placeholders)"
        );
        $entIdQuery->execute($sinch_prod_ids);
        return $entIdQuery->fetchAll(\PDO::FETCH_ASSOC, 0);
    }
}