<?php

namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class ChangeCartPrice
 * @package SITC\Sinchimport\Observer
 */
class ChangeCartPrice implements ObserverInterface
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

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->helper->isModuleEnabled('Tigren_CompanyAccount')) {
            return;
        }

        $accountGroup = $this->helper->getCurrentAccountGroupId();
        if(!empty($accountGroup)) {
            //TODO: Make sure to deal with the correct (i.e. selected) items when a bundle is involved
            $item = $observer->getEvent()->getQuoteItem();
            $item = $item->getParentItem() ? $item->getParentItem() : $item;

            $customPrice = $this->customPricingHelper->getAccountGroupPrice($accountGroup, $item->getProduct());
            if(!is_null($customPrice)){
                $item->setCustomPrice($customPrice);
                $item->setOriginalCustomPrice($customPrice);
                $item->getProduct()->setIsSuperMode(true);
            }
        }

        return $this;
    }
}