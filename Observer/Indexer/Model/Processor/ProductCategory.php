<?php

namespace SITC\Sinchimport\Observer\Indexer\Model\Processor;

use Magento\Framework\Event\ObserverInterface;

class ProductCategory implements ObserverInterface
{

    protected $_connection;
    protected $_resourceConnection;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ){
        $this->_resourceConnection       = $resourceConnection;
        $this->_connection = $this->_resourceConnection->getConnection();
    }

    private function _doQuery($query)
    {

        return $this->_connection->query($query);
    }

    protected function _getTableName($tableName = '')
    {
        if ($tableName) {
            return $this->_connection->getTableName($tableName);
        }

        return '';
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->_doQuery(
            "
            INSERT INTO ".$this->_getTableName(
                'catalog_category_product_index'
            ) ."
                (category_id, product_id, position, is_parent, store_id, visibility)
            (SELECT
                a.category_id,
                a.product_id,
                a.position,
                1,
                b.store_id,
                4
            FROM ". $this->_getTableName(
                'catalog_category_product'
            ) ." a
            JOIN ".$this->_getTableName('store')." b
            ) 
            ON DUPLICATE KEY UPDATE
                visibility = 4"
        );
    }
}