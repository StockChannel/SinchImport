<?php
namespace SITC\Sinchimport\Observer;

class CategoryCollectionLoadAfter implements \Magento\Framework\Event\ObserverInterface
{
    private $registry;
    private $helper;

    public function __construct(
        \Magento\Framework\Registry $registry,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        $this->registry = $registry;
        $this->helper = $helper;
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->helper->isProductVisibilityEnabled()){
            return; //No filtering if the feature isn't enabled
        }

        //the DontDepersonaliseAccount interceptor saves account group id prior to depersonalise
        $account_group_id = $this->registry->registry('sitc_account_group_id');

        if($this->helper->getStoreConfig('sinchimport/product_visibility/private_visible_to_guest') && $account_group_id === false) {
            return; //private_visible_to_guest is enabled and this is a guest (don't filter)
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $filteredProductCollection */
        $filteredProductCollection = $observer->getProductCollection();
        $productCollection = clone $filteredProductCollection;
        $filteredProductCollection->removeAllItems();

        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($productCollection as $product) {
            $sinch_restrict = $product->getSinchRestrict();
            if(empty($sinch_restrict)){ //If sinch_restrict is empty, product is always visible
                $filteredProductCollection->addItem($product);
                continue;
            }

            $product_account_groups = explode(",", $sinch_restrict);
            //If the product account groups contains the current account group, add it back
            if(in_array($account_group_id, $product_account_groups)){
                $filteredProductCollection->addItem($product);
            }
        }
    }
}