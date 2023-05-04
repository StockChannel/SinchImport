<?php

namespace SITC\Sinchimport\Plugin;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;

class Product {
    private Data $helper;
    private ResourceConnection $resourceConnection;
    
    public function __construct(
        Data               $helper,
        ResourceConnection $resourceConnection
    )
    {
        $this->helper = $helper;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Interceptor on getStatus() of a Product for CC
     * 
     * @param \Magento\Catalog\Model\Product $subject The product
     * @param int $result The original return value
     * @return int The modified result
     */
    public function afterGetStatus(
        \Magento\Catalog\Model\Product $subject,
        $result
    ){
        if(!$this->canSeeProduct($subject)) {
            return \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED;
        }
        return $result;
    }

    /**
     * Interceptor on getIsSalable() of a Product for CC
     * 
     * @param \Magento\Catalog\Model\Product $subject
     * @param bool $result
     * @return bool The modified result
     */
    public function afterGetIsSalable(
        \Magento\Catalog\Model\Product $subject,
        $result
    ){
        if(!$this->canSeeProduct($subject)) {
            return false;
        }
        return $result;
    }

    /**
     * Returns whether a product is visible to the current user (CC)
     * 
     * @param \Magento\Catalog\Model\Product $product The product
     * @return bool Can see
     */
    private function canSeeProduct(\Magento\Catalog\Model\Product $product): bool
    {
        if(!$this->helper->isProductVisibilityEnabled()) {
            return true;
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();
        return $this->helper->checkProductVisibility($product, $account_group_id);
    }
}
