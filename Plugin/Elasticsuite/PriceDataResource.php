<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Model\Indexer\Product\Price\DimensionCollectionFactory;
use Magento\Catalog\Model\Indexer\Product\Price\PriceTableResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Indexer\WebsiteDimensionProvider;
use Magento\Store\Model\StoreManagerInterface;
use Smile\ElasticsuiteCatalog\Model\ResourceModel\Product\Indexer\Fulltext\Datasource\PriceData as Subject;

class PriceDataResource
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly DimensionCollectionFactory $dimensionCollectionFactory,
        private readonly PriceTableResolver $priceTableResolver,
    ) {}

    /**
     * Replace the sparse price index read with a CROSS JOIN expansion so that
     * Elasticsearch receives one price document per (product, customer_group).
     *
     * The MySQL catalog_product_index_price table is intentionally sparse: only
     * group 3 (default) rows exist for all products, and additional rows are
     * created only for groups that have specific tier prices. Without this plugin,
     * ElasticSuite would only index the sparse rows, breaking layered navigation
     * price aggregations for non-default groups.
     *
     * The CROSS JOIN with customer_group plus a COALESCE on all price fields
     * produces one row per (product, group): group-specific when available,
     * otherwise falling back to the group-3 base price.
     */
    public function aroundLoadPriceData(Subject $subject, callable $proceed, $storeId, $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $websiteId  = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
        $connection = $this->resourceConnection->getConnection();
        $cgTable    = $this->resourceConnection->getTableName('customer_group');

        $baseSelects = [];
        foreach ($this->getPriceIndexTables($websiteId) as $indexTable) {
            $baseSelects[] = $connection->select()
                ->from(['g3' => $indexTable], [
                    'entity_id'         => 'g3.entity_id',
                    'customer_group_id' => 'cg.customer_group_id',
                    'website_id'        => 'g3.website_id',
                    'tax_class_id'      => new \Zend_Db_Expr('COALESCE(gx.tax_class_id, g3.tax_class_id)'),
                    'price'             => new \Zend_Db_Expr('COALESCE(gx.price, g3.price)'),
                    'final_price'       => new \Zend_Db_Expr('COALESCE(gx.final_price, g3.final_price)'),
                    'min_price'         => new \Zend_Db_Expr('COALESCE(gx.min_price, g3.min_price)'),
                    'max_price'         => new \Zend_Db_Expr('COALESCE(gx.max_price, g3.max_price)'),
                    'tier_price'        => new \Zend_Db_Expr('COALESCE(gx.tier_price, g3.tier_price)'),
                ])
                ->join(['cg' => $cgTable], '1=1', [])
                ->joinLeft(
                    ['gx' => $indexTable],
                    'gx.entity_id = g3.entity_id'
                    . ' AND gx.website_id = g3.website_id'
                    . ' AND gx.customer_group_id = cg.customer_group_id',
                    []
                )
                ->where('g3.customer_group_id = 3')
                ->where('g3.website_id = ?', $websiteId)
                ->where('g3.entity_id IN(?)', $productIds);
        }

        if (empty($baseSelects)) {
            return [];
        }

        $select = count($baseSelects) === 1
            ? reset($baseSelects)
            : $connection->select()->union($baseSelects);

        return $connection->fetchAll($select);
    }

    private function getPriceIndexTables(int $websiteId): array
    {
        $tables = [];
        foreach ($this->dimensionCollectionFactory->create() as $dimensions) {
            if (isset($dimensions[WebsiteDimensionProvider::DIMENSION_NAME])) {
                $value = (string) $dimensions[WebsiteDimensionProvider::DIMENSION_NAME]->getValue();
                if ($value !== (string) $websiteId) {
                    continue;
                }
            }
            $tables[] = $this->priceTableResolver->resolve('catalog_product_index_price', $dimensions);
        }
        return $tables;
    }
}
