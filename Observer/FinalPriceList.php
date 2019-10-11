<?php

namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class FinalPriceList
 * @package SITC\Sinchimport\Observer
 */
class FinalPriceList implements ObserverInterface
{
    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;
    /** @var \SITC\Sinchimport\Helper\CustomPricing $customPricingHelper */
    private $customPricingHelper;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \SITC\Sinchimport\Helper\CustomPricing $customPricingHelper
    ){
        $this->helper = $helper;
        $this->customPricingHelper = $customPricingHelper;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this|void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->helper->isModuleEnabled('Tigren_CompanyAccount')) {
            return;
        }

        $accountGroup = $this->helper->getCurrentAccountGroupId();
        if(!empty($accountGroup)) {
            $collectionProduct = $observer->getEvent()->getcollection();
            $productIds = [];
            foreach($collectionProduct->getItems() as $product){
                $productIds[] = $product->getEntityId();
            }

            $rules = $this->customPricingHelper->getAccountGroupPrices($accountGroup, $productIds);
            foreach($collectionProduct->getItems() as $product){
                $productId = $product->getEntityId();
                foreach($rules as $rule){
                    if($rule['product_id'] != $productId){
                        continue;
                    }
                    if(isset($rule['customer_group_price'])){
                        $this->customPricingHelper->setProductPrice($product, $rule['customer_group_price']);
                        break;
                    }
                }
            }
        }

        return $this;
    }
}