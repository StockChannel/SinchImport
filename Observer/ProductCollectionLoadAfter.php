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
        if(!$this->helper->isProductVisibilityEnabled()){
            return; //No filtering if the feature isn't enabled
        }

        //the DontDepersonaliseAccount interceptor saves account group id prior to depersonalise
        $account_group_id = $this->registry->registry('sitc_account_group_id');

        if($this->helper->getStoreConfig('sinchimport/product_visibility/private_visible_to_guest') && $account_group_id === false) {
            return; //private_visible_to_guest is enabled and this is a guest (don't filter)
        }

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

            $product_account_groups = explode(",", $sinch_restrict);
            
            if($this->areRulesInverted($product_account_groups)){
                //If not logged in or not prevented, show
                if($account_group_id === false || !$this->shouldPrevent($account_group_id, $product_account_groups)) {
                    $filteredProductCollection->addItem($product);
                }
            } else if(in_array($account_group_id, $product_account_groups)){
                //If the product account groups contains the current account group, add it back (also conveniently ignores entries with ! in front, so still works with prevention rules)
                $filteredProductCollection->addItem($product);
            }
        }
    }

    /**
     * Returns true if the product should specifically NOT be shown to the given account group.
     * @param int $account_group_id
     * @param string[] $product_account_groups
     * @return bool
     */
    private function shouldPrevent($account_group_id, $product_account_groups)
    {
        foreach($product_account_groups as $product_acc_grp){
            if(substr($product_acc_grp, 0, 1) == "!"){
                $prevent_account = substr($product_acc_grp, 1);
                if($account_group_id == $prevent_account) {
                    return true;
                }
            }
        }
    }

    /**
     * Returns true if ALL account groups in the set are inverted rules (i.e the product should be shown publically)
     * @param string[] $product_account_groups
     * @return bool
     */
    private function areRulesInverted($product_account_groups)
    {
        $all_inverted = true;
        foreach($product_account_groups as $product_acc_grp){
            $all_inverted &= substr($product_acc_grp, 0, 1) == "!";
        }
        return $all_inverted;
    }
}