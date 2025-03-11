<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Catalog\Model\ResourceModel\Product\Action;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\StateException;
use PDO;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class UNSPSC extends AbstractImportSection {
    const LOG_PREFIX = "UNSPSC: ";
    const LOG_FILENAME = "unspsc";
    const ATTRIBUTE_NAME = "unspsc";

    private $hasParseRun = false;
    private $enableLogging = false;

    private $cacheType;
    private $massProdValues;

    private $productTempTable;
    private $cpeTable;

    /**
     * Mapping of UNSPSC -> [Sinch Product ID]
     */
    private $mapping = [];

    public function __construct(
        ResourceConnection $resourceConn,
        ConsoleOutput $output,
        Download $dlHelper,
        TypeListInterface $cacheType,
        Action $massProdValues
    ){
        parent::__construct($resourceConn, $output, $dlHelper);
        $this->cacheType = $cacheType;
        $this->massProdValues = $massProdValues;

        $this->productTempTable = $this->getTableName('products_temp');
        $this->cpeTable = $this->getTableName('catalog_product_entity');
    }

    public function getRequiredFiles(): array
    {
        return [];
    }

    public function parse(): void
    {
        $this->log("--- Begin UNSPSC Mapping ---");

        $unspsc_values = $this->getConnection()->fetchCol("SELECT DISTINCT unspsc FROM {$this->productTempTable} WHERE unspsc IS NOT NULL");
        foreach($unspsc_values as $unspsc){
            //List of Sinch products with the specified UNSPSC value
            $sinch_ids = $this->getConnection()->fetchCol(
                "SELECT sinch_product_id FROM {$this->productTempTable} WHERE unspsc = :unspsc",
                [":unspsc" => $unspsc]
            );

            $this->mapping[$unspsc] = $sinch_ids;
        }

        $this->hasParseRun = true;
        $this->log("--- Completed UNSPSC mapping ---");
    }

    public function apply(): void
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
                throw new StateException(__("Failed to retrieve entity ids"));
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
    private function sinchToEntityIds(array $sinch_prod_ids): array
    {
        $placeholders = implode(',', array_fill(0, count($sinch_prod_ids), '?'));
        $entIdQuery = $this->getConnection()->prepare(
            "SELECT entity_id FROM {$this->cpeTable} WHERE sinch_product_id IN ($placeholders)"
        );
        $entIdQuery->execute($sinch_prod_ids);
        return $entIdQuery->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    protected function log($msg, $print = true): void
    {
        if($this->enableLogging){
            parent::log($msg, $print);
        }
    }
}
