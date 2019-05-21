<?php

namespace SITC\Sinchimport\Model\Import;

class UNSPSC extends AbstractImportSection {

    const ATTRIBUTE_NAME = "unspsc";
    const PRODUCT_PAGE_SIZE = 50;

    private $hasParseRun = false;
    private $enableLogging = false;

    private $cacheType;
    private $massProdValues;

    private $logger;

    private $productTempTable;
    private $cpeTable;

    /**
     * Mapping of UNSPSC -> [Sinch Product ID]
     */
    private $mapping = [];

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Framework\App\Cache\TypeListInterface $cacheType,
        \Magento\Catalog\Model\ResourceModel\Product\Action $massProdValues
    ){
        parent::__construct($resourceConn);
        $this->cacheType = $cacheType;
        $this->massProdValues = $massProdValues;

        $this->productTempTable = $this->getTableName('products_temp');
        $this->cpeTable = $this->getTableName('catalog_product_entity');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/sinch_unspsc.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
    }

    public function parse()
    {
        $this->log("--- Begin UNSPSC Mapping ---");

        $unspsc_values = $this->getConnection()->fetchCol("SELECT DISTINCT unspsc FROM {$this->productTempTable} WHERE unspsc IS NOT NULL");
        foreach($unspsc_values as $unspsc){
            //List of Sinch products with the specified UNSPSC value
            $sinch_ids = $this->getConnection()->fetchCol(
                "SELECT store_product_id FROM {$this->productTempTable} WHERE unspsc = :unspsc",
                [":unspsc" => $unspsc]
            );

            $this->mapping[$unspsc] = $sinch_ids;
        }

        $this->hasParseRun = true;
        $this->log("--- Completed UNSPSC mapping ---");
    }

    public function apply()
    {
        if(!$this->hasParseRun) {
            $this->log("Not applying UNSPSC values as parse hasn't run");
            return;
        }
        
        $this->log("--- Begin applying UNSPSC values ---");
        $applyStart = $this->microtime_float();

        $valueCount = count($this->mapping);
        $currIter = 0;

        foreach($this->mapping as $unspsc => $sinch_ids){
            $currIter += 1;

            $entityIds = $this->sinchToEntityIds($sinch_ids);
            if($entityIds === false){
                $this->logger->err("Failed to retreive entity ids");
                throw new \Magento\Framework\Exception\StateException(__("Failed to retrieve entity ids"));
            }

            $productCount = count($entityIds);
            $this->log("({$currIter}/{$valueCount}) Setting UNSPSC to {$unspsc} for {$productCount} products");

            $this->massProdValues->updateAttributes(
                $entityIds, 
                [self::ATTRIBUTE_NAME => $unspsc],
                0 //store id (dummy value as they're global attributes)
            );
        }

        //Reset the mapping array to save memory
        $this->mapping = [];
        $this->hasParseRun = false;

        
        //Flush EAV cache
        $this->cacheType->cleanType('eav');

        $elapsed = number_format($this->microtime_float() - $applyStart, 2);
        $this->log("--- Completed applying UNSPSC values in {$elapsed} seconds");
    }

    /**
     * Convert Sinch Product IDs to Product Entity IDs
     * 
     * @param int[] $sinch_prod_ids Sinch Product IDs
     * @return int[] Product Entity IDs
     */
    private function sinchToEntityIds($sinch_prod_ids)
    {
        $placeholders = implode(',', array_fill(0, count($sinch_prod_ids), '?'));
        $entIdQuery = $this->getConnection()->prepare(
            "SELECT entity_id FROM {$this->cpeTable} WHERE sinch_product_id IN ($placeholders)"
        );
        $entIdQuery->execute($sinch_prod_ids);
        return $entIdQuery->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    private function log($msg)
    {
        if($this->enableLogging){
            $this->logger->info($msg);
        }
    }
}