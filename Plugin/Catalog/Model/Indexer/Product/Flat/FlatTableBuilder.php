<?php

namespace SITC\Sinchimport\Plugin\Catalog\Model\Indexer\Product\Flat;

use Closure;
use Magento\Catalog\Helper\Product\Flat\Indexer;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class FlatTableBuilder
{

    /**
     * @var AdapterInterface
     */
    protected $_connection;

    /**
     * @var ResourceConnection
     */
    protected $_resource;

    /**
     * @var Indexer
     */
    protected $_productIndexerHelper;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        ResourceConnection $resource,
        Indexer $productIndexerHelper
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
        Closure $proceed,
        $storeId,
        $changedIds,
        $valueFieldSuffix,
        $tableDropSuffix,
        $fillTmpTables
    ) {
        $proceed($storeId, $changedIds, $valueFieldSuffix, $tableDropSuffix, $fillTmpTables);
        $connection = $this->_resource->getConnection();
        $flatTable = $this->_productIndexerHelper->getFlatTableName($storeId);
        $catalog_product_entity = $this->_resource->getTableName('catalog_product_entity');
        $sql = "UPDATE {$flatTable} as t2 INNER JOIN {$catalog_product_entity} AS e SET t2.sinch_product_id = e.sinch_product_id where t2.entity_id = e.entity_id";
        $connection->query($sql);
        return $this;
    }
}
