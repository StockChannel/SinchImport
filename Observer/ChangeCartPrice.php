<?php

namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class ChangeCartPrice
 * @package SITC\Sinchimport\Observer
 */
class ChangeCartPrice implements ObserverInterface
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

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->helper->isModuleEnabled('Tigren_CompanyAccount')) {
            return;
        }
        //This observer runs prior to the depersonalise routine and thus doesn't need the registry hack
        $accountGroup = $this->helper->getCurrentAccountGroupId();

        if(!empty($accountGroup)) {
            $item = $observer->getEvent()->getQuoteItem();
            $item = ( $item->getParentItem() ? $item->getParentItem() : $item );

            $rules = $this->getAccountGroupPrice($accountGroup, $item->getProduct()->getId());
            foreach($rules as $rule){
                if($rule['price_type_id'] == 1 && isset($rule['customer_group_price'])){
                    $item->setCustomPrice($rule['customer_group_price']);
                    $item->setOriginalCustomPrice($rule['customer_group_price']);
                    $item->getProduct()->setIsSuperMode(true);
                }
            }
        }

        return $this;
    }
}