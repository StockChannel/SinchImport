<?php

namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class FinalPriceList
 * @package SITC\Sinchimport\Observer
 */
class FinalPriceList implements ObserverInterface
{
    /**
     * @var \Magento\Framework\App\ResourceConnection $resourceConn
     */
    private $resourceConn;
    /**
     * @var \Magento\Framework\Registry $registry
     */
    private $registry;
    /**
     * @var \SITC\Sinchimport\Helper\Data $helper
     */
    private $helper;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Framework\Registry $registry,
        \SITC\Sinchimport\Helper\Data $helper
    ){
        $this->resourceConn = $resourceConn;
        $this->registry = $registry;
        $this->helper = $helper;
    }

    /**
     * Get Price rules matching a given account group and set of products
     * 
     * @param int $accountGroup Account Group ID
     * @param int[] $products Product IDs
     * @return array
     */
    private function getAccountGroupPrices($accountGroup, $products)
    {
        $select = $this->resourceConn->getConnection()->select()
            ->from($this->resourceConn->getTableName('sinch_customer_group_price'))
            ->where('group_id = ?', $accountGroup)
            ->where('product_id IN (?)', $products)
            ->where('customer_group_price > 0');
        
        return $this->resourceConn->getConnection()->fetchAll($select);
        //return $this->_customerGroupPriceFactory
        //    ->create()
        //    ->addFieldToFilter('group_id', ["eq" => $accountGroup])
        //    ->addFieldToFilter('product_id', ["in" => $products]);
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
        //the DontDepersonaliseAccount interceptor saves account group id prior to depersonalise
        $accountGroup = $this->registry->registry('sitc_account_group_id');

        if(!empty($accountGroup)) {
            $collectionProduct = $observer->getEvent()->getcollection();

            $productIds = [];
            foreach($collectionProduct->getItems() as $product){
                $productIds[] = $product->getEntityId();
            }
            $rules = $this->getAccountGroupPrices($accountGroup, $productIds);

            foreach($collectionProduct->getItems() as $product){
                $productId = $product->getEntityId();
                foreach($rules as $rule){
                    if($rule['product_id'] != $productId){
                        continue;
                    }
                    if($rule['price_type_id'] == 1 && isset($rule['customer_group_price'])){
                        $product->setPrice($rule['customer_group_price']);
                        $product->setMinPrice($rule['customer_group_price']);
                        $product->setMinimalPrice($rule['customer_group_price']);
                        $product->setMaxPrice($rule['customer_group_price']);
                        $product->setTierPrice($rule['customer_group_price']);
                        $product->setFinalPrice($rule['customer_group_price']);
                        break;
                    }
                }
            }
        }

        return $this;
    }
}