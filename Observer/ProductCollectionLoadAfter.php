<?php
namespace SITC\Sinchimport\Observer;

class ProductCollectionLoadAfter implements \Magento\Framework\Event\ObserverInterface
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
        if(!$this->helper->isProductVisibilityEnabled() || $this->helper->isModuleEnabled('Smile_ElasticsuiteCatalog')){
            return; //No filtering if the feature isn't enabled or with Elasticsuite enabled (as thats handled by Plugin\Elasticsuite\ContainerConfiguration)
        }

        //the DontDepersonaliseAccount interceptor saves account group id prior to depersonalise
        $account_group_id = $this->registry->registry('sitc_account_group_id');

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $filteredProductCollection */
        $filteredProductCollection = $observer->getCollection();
        $productCollection = clone $filteredProductCollection;
        $filteredProductCollection->removeAllItems();

        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($productCollection as $product) {
            $sinch_restrict = $product->getSinchRestrict();
            
            if(empty($sinch_restrict)){ //If sinch_restrict is empty, product is always visible
                $filteredProductCollection->addItem($product);
                continue;
            }
            
            $blacklist = substr($sinch_restrict, 0, 1) == "!";
            if($blacklist) {
                $sinch_restrict = substr($sinch_restrict, 1);
            }
            $product_account_groups = explode(",", $sinch_restrict);

            if((!$blacklist && in_array($account_group_id, $product_account_groups)) || //Whitelist and account group in list
                ($blacklist && !in_array($account_group_id, $product_account_groups))) { //Blacklist and account group not in list
                $filteredProductCollection->addItem($product);
            }
        }
    }
}