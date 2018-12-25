<?php

namespace SITC\Sinchimport\Plugin\Catalog\Model\Indexer\Product\Flat;

/**
 * Class FlatTableBuilder
 * @package SITC\Sinchimport\Plugin\Catalog\Model\Indexer\Product\Flat
 */
class FlatTableBuilder
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_connection;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;

    /**
     * @var \Magento\Catalog\Helper\Product\Flat\Indexer
     */
    protected $_productIndexerHelper;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Catalog\Helper\Product\Flat\Indexer $productIndexerHelper
    ) {
        $this->_resource = $resource;
        $this->_productIndexerHelper = $productIndexerHelper;
    }

    /**
     * Retrieve image URL
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return                                        string
     */
    public function aroundBuild(
        \Magento\Catalog\Model\Indexer\Product\Flat\FlatTableBuilder $subject,
        \Closure $proceed,
        $storeId,
        $changedIds,
        $valueFieldSuffix,
        $tableDropSuffix,
        $fillTmpTables
    ) {
        $proceed($storeId, $changedIds, $valueFieldSuffix, $tableDropSuffix, $fillTmpTables);
        $connection = $this->_resource->getConnection();
        $flatTable = $this->_productIndexerHelper->getFlatTableName($storeId);
        $sql = "UPDATE {$flatTable} as t2 INNER JOIN catalog_product_entity AS e SET t2.store_product_id = e.store_product_id, t2.sinch_product_id = e.sinch_product_id where t2.entity_id = e.entity_id";
        $connection->query($sql);
        return $this;
    }
}
