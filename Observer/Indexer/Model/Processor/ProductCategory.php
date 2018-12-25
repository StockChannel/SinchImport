<?php

namespace SITC\Sinchimport\Observer\Indexer\Model\Processor;

use Magento\Framework\Event\ObserverInterface;

/**
 * Class ProductCategory
 * @package SITC\Sinchimport\Observer\Indexer\Model\Processor
 */
class ProductCategory implements ObserverInterface
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resourceConnection;

    /**
     * ProductCategory constructor.
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->_resourceConnection = $resourceConnection;
        $this->_connection = $this->_resourceConnection->getConnection();
    }

    /**
     * @param $query
     * @return \Zend_Db_Statement_Interface
     */
    private function _doQuery($query)
    {

        return $this->_connection->query($query);
    }

    /**
     * @param $tableName
     * @return string
     */
    private function _getTableName($tableName)
    {
        return $this->_resourceConnection->getTableName($tableName);
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
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
