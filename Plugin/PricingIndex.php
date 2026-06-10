<?php

namespace SITC\Sinchimport\Plugin;

use Magento\Catalog\Model\ResourceModel\Product\Indexer\Price\Query\BaseFinalPrice;
use Magento\Framework\DB\Select;

class PricingIndex
{
    /**
     * @param BaseFinalPrice $subject
     * @param Select $result
     * @param array $dimensions
     * @param string $productType
     * @param array $entityIds
     * @return Select
     */
    public function afterGetQuery(BaseFinalPrice $subject,
        $result,
        array $dimensions,
        string $productType,
        array $entityIds = []
    ): Select
    {
        // Always keep rows for the default group (3) so products without tier prices remain in the index.
        // For all other groups, only keep rows where at least one tier price exists.
        $condition = ["cg.customer_group_id = 3"];
        for ($i = 1; $i < 5; $i++) {
            $condition[] = "tier_price_{$i}.value_id IS NOT NULL";
        }
        $result->where(implode(' OR ', $condition));

        return $result;
    }

}