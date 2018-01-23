<?php

namespace SITC\Sinchimport\Plugin\Catalog\Layer;

class FilterList
{
    /**
     * DB connection
     *
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;
    
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;
    
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->objectManager = $objectManager;
        $this->connection    = $resourceConnection->getConnection();
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetFilters(
        \Magento\Catalog\Model\Layer\FilterList $subject,
        \Closure $proceed,
        $layer
    ) {
        $filters = $proceed($layer);
        
        foreach ($this->getFilterableFeatures($layer) as $feature) {
            $filters[] = $this->objectManager->create(
                'SITC\Sinchimport\Model\Layer\Filter\Feature',
                ['layer' => $layer]
            )->setFeatureModel($feature);
        }
        
        return $filters;
    }
    
    public function getFilterableFeatures($layer)
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        
        $connection           = $this->connection;
        $category             = $layer->getCurrentCategory();
        $categoryId           = $category->getEntityId();
        $featureTable         = $connection->getTableName(
            'sinch_categories_features'
        );
        $restrictedValueTable = $connection->getTableName(
            'sinch_restricted_values'
        );
        $categoryMappingTable = $connection->getTableName(
            'sinch_categories_mapping'
        );
        
        $select = $connection->select()
            ->from(array('cf' => $featureTable))
            ->joinInner(
                array('rv' => $restrictedValueTable),
                'cf.category_feature_id = rv.category_feature_id'
            )
            ->joinInner(
                array('cm' => $categoryMappingTable),
                'cf.store_category_id = cm.store_category_id'
            )
            ->where('cm.shop_entity_id = ' . $categoryId)
            ->group('cf.feature_name')
            ->order('cf.display_order_number', 'asc')
            ->order('cf.feature_name', 'asc')
            ->order('rv.display_order_number', 'asc')
            ->columns('cf.feature_name AS name')
            ->columns('cf.category_feature_id as feature_id')
            ->columns(
                'GROUP_CONCAT(`rv`.`text` SEPARATOR "\n") as restricted_values'
            );
        
        $result = $connection->fetchAll($select);
        
        \Magento\Framework\Profiler::stop(__METHOD__);
        
        return $result;
    }
}
