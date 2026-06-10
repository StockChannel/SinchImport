<?php

namespace SITC\Sinchimport\Plugin\Catalog\ResourceModel\Product;

use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;

/**
 * Rewrite the price_index join added by _productLimitationPrice() so it works
 * against a sparse catalog_product_index_price table (only group 3 rows exist
 * for every product, plus extra rows for groups with their own tier prices).
 *
 * This is implemented as a plugin (rather than a Collection preference/subclass)
 * because Smile_ElasticsuiteCatalog's Fulltext\Collection extends
 * Magento\Catalog\Model\ResourceModel\Product\Collection directly - a preference
 * on that class would never apply to it, but a plugin on its public methods does.
 */
class PriceIndexCollection
{
    private const DEFAULT_CUSTOMER_GROUP_ID = 3;

    public function afterAddPriceData(Collection $subject, $result)
    {
        $this->rewritePriceIndexJoin($subject);

        return $result;
    }

    public function afterApplyFrontendPriceLimitations(Collection $subject, $result)
    {
        $this->rewritePriceIndexJoin($subject);

        return $result;
    }

    private function rewritePriceIndexJoin(Collection $collection): void
    {
        $filters = $collection->getLimitationFilters();

        $customerGroupId = (int)($filters['customer_group_id'] ?? 0);
        $websiteId = (int)($filters['website_id'] ?? 0);

        if ($customerGroupId === self::DEFAULT_CUSTOMER_GROUP_ID || $websiteId === 0) {
            return;
        }

        $select = $collection->getSelect();

        try {
            $fromPart = $select->getPart(Select::FROM);
        } catch (\Zend_Db_Select_Exception $e) {
            return;
        }

        if (!isset($fromPart['price_index'])) {
            return;
        }

        $existingEntry = $fromPart['price_index'];

        // Already rewritten (e.g. addPriceData() and applyFrontendPriceLimitations()
        // both ran on the same collection) - leave it alone.
        if ($existingEntry['tableName'] instanceof \Zend_Db_Expr) {
            return;
        }

        $connection = $collection->getConnection();
        $priceIndexTable = $collection->getResource()->getTable('catalog_product_index_price');

        // Build a derived table that returns exactly one row per product:
        // - Anchor on default group (3) rows, which exist for ALL products (see PricingIndex plugin).
        // - LEFT JOIN the customer's specific group row; if it exists COALESCE prefers it.
        // This avoids duplicate rows that would occur with customer_group_id IN (X, 3).
        //
        // customer_group_id and website_id are included so that if Magento calls
        // _productLimitationPrice() again later (e.g. during _applyProductLimitations()),
        // its join-condition rewrite (price_index.customer_group_id = X AND
        // price_index.website_id = W) still references valid columns and is a no-op.
        $subquery = $connection->select()
            ->from(['g3' => $priceIndexTable], [
                'entity_id'         => 'g3.entity_id',
                'customer_group_id' => new \Zend_Db_Expr((string)$customerGroupId),
                'website_id'        => 'g3.website_id',
                'tax_class_id'      => new \Zend_Db_Expr('COALESCE(gx.tax_class_id, g3.tax_class_id)'),
                'price'             => new \Zend_Db_Expr('COALESCE(gx.price, g3.price)'),
                'final_price'       => new \Zend_Db_Expr('COALESCE(gx.final_price, g3.final_price)'),
                'min_price'         => new \Zend_Db_Expr('COALESCE(gx.min_price, g3.min_price)'),
                'max_price'         => new \Zend_Db_Expr('COALESCE(gx.max_price, g3.max_price)'),
                'tier_price'        => new \Zend_Db_Expr('COALESCE(gx.tier_price, g3.tier_price)'),
            ])
            ->joinLeft(
                ['gx' => $priceIndexTable],
                $connection->quoteInto(
                    'gx.entity_id = g3.entity_id AND gx.website_id = g3.website_id AND gx.customer_group_id = ?',
                    $customerGroupId
                ),
                []
            )
            ->where('g3.customer_group_id = ?', self::DEFAULT_CUSTOMER_GROUP_ID)
            ->where('g3.website_id = ?', $websiteId);

        $fromPart['price_index'] = [
            'joinType'      => $existingEntry['joinType'],
            'schema'        => null,
            'tableName'     => new \Zend_Db_Expr('(' . $subquery->assemble() . ')'),
            'joinCondition' => 'price_index.entity_id = e.entity_id',
        ];
        $select->setPart(Select::FROM, $fromPart);
    }
}
