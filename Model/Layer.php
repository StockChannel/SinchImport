<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model;

class Layer extends \Magento\Catalog\Model\Layer
{
    public function getFilterableFeatures()
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        $category       = $this->getCurrentCategory();
        $categoryId     = $category->getEntityId();
        $tCategor       = $this->_resource->getTableName('sinch_categories');
        $tCatFeature    = $this->_resource->getTableName(
            'sinch_categories_features'
        );
        $tRestrictedVal = $this->_resource->getTableName(
            'sinch_restricted_values'
        );
        $tCategMapp     = $this->_resource->getTableName(
            'sinch_categories_mapping'
        );
        
        $connection = $this->_resource->getConnection();
        
        $select = $connection->select()
            ->from(['cf' => $tCatFeature])
            ->joinInner(
                ['rv' => $tRestrictedVal],
                'cf.category_feature_id = rv.category_feature_id'
            )
            ->joinInner(
                ['cm' => $tCategMapp],
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
