<?php

namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class FinalPrice
 * @package SITC\Sinchimport\Observer
 */
class FinalPrice implements ObserverInterface
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
     * Get Price rules matching a given account group and product
     * 
     * @param int $accountGroup Account Group ID
     * @param int $productId Product ID
     * @return array
     */
    private function getAccountGroupPrice($accountGroup, $productId)
    {
        $select = $this->resourceConn->getConnection()->select()
            ->from($this->resourceConn->getTableName('sinch_customer_group_price'))
            ->where('group_id = ?', $accountGroup)
            ->where('product_id = ?', $productId)
            ->where('customer_group_price > 0');
        
        return $this->resourceConn->getConnection()->fetchAll($select);
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
            $product = $observer->getEvent()->getProduct();
            $rules = $this->getAccountGroupPrice($accountGroup, $product->getId());
            foreach($rules as $rule){
                if($rule['price_type_id'] == 1 && isset($rule['customer_group_price'])){
                    $product->setPrice($rule['customer_group_price']);
                    $product->setMinPrice($rule['customer_group_price']);
                    $product->setMinimalPrice($rule['customer_group_price']);
                    $product->setMaxPrice($rule['customer_group_price']);
                    $product->setTierPrice($rule['customer_group_price']);
                    $product->setFinalPrice($rule['customer_group_price']);
                }
            }
        }

        return $this;
    }
}