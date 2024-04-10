<?php


namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Attribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;


/**
 * Interceptor on Elasticsuite indexing to populate values for product stock attribute
 *
 * @package SITC\Sinchimport\Plugin\Elasticsuite
 */
class InventoryData
{

    const LOG_PREFIX = 'InStockFilter: ';
    /**
     * Filter attribute code
     */
    const IN_STOCK_FILTER_CODE = 'sinch_in_stock';

    /**
     * @var Data
     */
    private $helper;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var AdapterInterface
     */
    private $connection;
    /**
     * @var string
     */
    private $catalog_product_entity_varchar;
    /**
     * @var int
     */
    private $attrId;

    public function __construct(
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        Attribute $eavAttribute,
        Data $helper
    ){
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->connection = $resourceConnection->getConnection();
        $this->helper = $helper;

        $this->catalog_product_entity_varchar = $this->connection->getTableName('catalog_product_entity_varchar');
        $this->attrId = $eavAttribute->getIdByCode('catalog_product', self::IN_STOCK_FILTER_CODE);
    }

    /**
     * Add stock status to product data, so we can filter on it
     *
     * @param \Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\InventoryData $subject
     * @param $result
     * @param $storeId
     * @return mixed
     */
    public function afterAddData(\Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\InventoryData $subject, $result, $storeId)
    {
        if (empty($this->helper->isInStockFilterEnabled())) {
            return $result;
        }
        $this->log("Processing addData for store " . $storeId);
        $inStockValue = $this->helper->getStoreConfig('sinchimport/stock/stock_filter/in_stock_value');
        $outOfStockValue = $this->helper->getStoreConfig('sinchimport/stock/stock_filter/out_of_stock_value');
        $this->connection->query(
            "DELETE FROM {$this->catalog_product_entity_varchar} WHERE attribute_id = :attrId AND value NOT IN (:inStock, :outStock)",
            [
                'attrId' => $this->attrId,
                'inStock' => $inStockValue,
                'outStock' => $outOfStockValue
            ]
        );

        $productStatuses = [];
        foreach ($result as $prodId => $indexData) {
            $isInStock = (isset($indexData['stock'], $indexData['stock']['qty']) && $indexData['stock']['qty'] > 0) ? $inStockValue : $outOfStockValue;
            $productStatuses[] = [
                'attribute_id' => $this->attrId,
                'store_id' => $storeId,
                'entity_id' => $prodId,
                'value' => $isInStock
            ];
        }
        $count = count($productStatuses);
        $rows = $this->connection->insertOnDuplicate($this->catalog_product_entity_varchar, $productStatuses);
        $this->log("Processed stock filter for $count products. Inserted/Updated $rows rows");
        return $result;
    }

    private function log($msg)
    {
        $this->logger->info(self::LOG_PREFIX . $msg);
    }
}