<?php

namespace SITC\Sinchimport\Plugin;

class Product {
    /** @var \SITC\Sinchimport\Model\Helper $helper */
    private $helper;
    
    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper
    )
    {
        $this->helper = $helper;
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
    private function canSeeProduct(\Magento\Catalog\Model\Product $product)
    {
        if(!$this->helper->isProductVisibilityEnabled()) {
            return true;
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();
        $sinch_restrict = $product->getSinchRestrict();
            
        if(empty($sinch_restrict)){ //If sinch_restrict is empty, product is always visible
            return true;
        }
        
        $blacklist = substr($sinch_restrict, 0, 1) == "!";
        if($blacklist) {
            $sinch_restrict = substr($sinch_restrict, 1);
        }
        $product_account_groups = explode(",", $sinch_restrict);

        if((!$blacklist && in_array($account_group_id, $product_account_groups)) || //Whitelist and account group in list
            ($blacklist && !in_array($account_group_id, $product_account_groups))) { //Blacklist and account group not in list
            return true;
        }
        return false;
    }
}