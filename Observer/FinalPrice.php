<?php

namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class FinalPrice
 * @package SITC\Sinchimport\Observer
 */
class FinalPrice implements ObserverInterface
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
            $product = $observer->getEvent()->getProduct();
            $customPrice = $this->customPricingHelper->getAccountGroupPrice($accountGroup, $product);
            if(!is_null($customPrice)){
                $this->customPricingHelper->setProductPrice($product, $customPrice);
            }
        }

        return $this;
    }
}