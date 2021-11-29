<?php


namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Attribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Logger\Logger;


/**
 * Interceptor on Elasticsuite indexing to populate values for product stock attribute
 *
 * @package SITC\Sinchimport\Plugin\Elasticsuite
 */
class InventoryData
{
    const IN_STOCK_FILTER_CODE = 'sinch_in_stock';

    private Logger $logger;
    private StoreManagerInterface $storeManager;
    private AdapterInterface $connection;

    private string $catalog_product_entity_int;
    private int $attrId;

    public function __construct(
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection,
        Attribute $eavAttribute
    ){
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->connection = $resourceConnection->getConnection();

        $this->catalog_product_entity_int = $this->connection->getTableName('catalog_product_entity_int');
        $this->attrId = $eavAttribute->getIdByCode('catalog_product', self::IN_STOCK_FILTER_CODE);
    }

    /**
     * Add stock status to product data, so we can filter on it
     *
     * @param \Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\InventoryData $subject
     * @param $result
     */
    public function afterAddData(\Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\InventoryData $subject, $result)
    {
        $count = 0;
        $storeId = $this->storeManager->getDefaultStoreView()->getId();
        foreach ($result as $prodId => $indexData) {
            $isInStock = $indexData['stock']['is_in_stock'] ?? 0;
            $this->connection->query("
                INSERT INTO {$this->catalog_product_entity_int} (attribute_id, store_id, entity_id, value)
                VALUES (:attributeId, :storeId, :entityId, :value)",
                [':attributeId' => $this->attrId, ':storeId' => $storeId, ':entityId' => $prodId, ':value' => (int)$isInStock]
            );
            $count++;
        }

        $this->logger->info("Processed stock filter for $count products");
    }
}