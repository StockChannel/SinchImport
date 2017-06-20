<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Model\Layer;

use Magento\Catalog\Api\CategoryRepositoryInterface;

class FilterList
{
    const CATEGORY_FILTER   = 'category';
    const ATTRIBUTE_FILTER  = 'attribute';
    const PRICE_FILTER      = 'price';
    const DECIMAL_FILTER    = 'decimal';

    /**
     * Filter factory
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var FilterableAttributeListInterface
     */
    protected $filterableAttributes;

    /**
     * @var string[]
     */
    protected $filterTypes = [
        self::CATEGORY_FILTER  => 'Magento\Catalog\Model\Layer\Filter\Category',
        self::ATTRIBUTE_FILTER => 'Magento\Catalog\Model\Layer\Filter\Attribute',
        self::PRICE_FILTER     => 'Magento\Catalog\Model\Layer\Filter\Price',
        self::DECIMAL_FILTER   => 'Magento\Catalog\Model\Layer\Filter\Decimal',
    ];

    /**
     * @var \Magento\Catalog\Model\Layer\Filter\AbstractFilter[]
     */
    protected $filters = [];

    /**
     * Resource
     *
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;

    /**
     * Store manager
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $registry = null;

    /**
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param FilterableAttributeListInterface $filterableAttributes
     * @param array $filters
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        FilterableAttributeListInterface $filterableAttributes,
        \Magento\Framework\App\ResourceConnection $resource,
        CategoryRepositoryInterface $categoryRepository,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Registry $registry,
        array $filters = []
    ) {
        $this->objectManager = $objectManager;
        $this->filterableAttributes = $filterableAttributes;
        $this->_resource = $resource;
        $this->categoryRepository = $categoryRepository;
        $this->_storeManager = $storeManager;
        $this->registry = $registry;

        /** Override default filter type models */
        $this->filterTypes = array_merge($this->filterTypes, $filters);
    }

    /**
     * Retrieve list of filters
     *
     * @param \Magento\Catalog\Model\Layer $layer
     * @return array|Filter\AbstractFilter[]
     */
    public function getFilters(\Magento\Catalog\Model\Layer $layer)
    {
        if (!count($this->filters)) {
            $this->filters = [
                $this->objectManager->create($this->filterTypes[self::CATEGORY_FILTER], ['layer' => $layer]),
            ];
            foreach ($this->filterableAttributes->getList() as $attribute) {
                $this->filters[] = $this->createAttributeFilter($attribute, $layer);
            }

            // Toanhandsome da them doan code nay
            foreach ($this->getFilterableFeatures() as $feature) {
                $this->filters[] = $this->objectManager->create(
                    'Magebuzz\Sinchimport\Model\Layer\Filter\Feature',
                    ['layer' => $layer]
                )->setFeatureModel($feature);
            }
        }
        return $this->filters;
    }

    /**
     * Create filter
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @param \Magento\Catalog\Model\Layer $layer
     * @return \Magento\Catalog\Model\Layer\Filter\AbstractFilter
     */
    protected function createAttributeFilter(
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute,
        \Magento\Catalog\Model\Layer $layer
    ) {
        $filterClassName = $this->getAttributeFilterClass($attribute);

        $filter = $this->objectManager->create(
            $filterClassName,
            ['data' => ['attribute_model' => $attribute], 'layer' => $layer]
        );
        return $filter;
    }

    /**
     * Get Attribute Filter Class Name
     *
     * @param \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute
     * @return string
     */
    protected function getAttributeFilterClass(\Magento\Catalog\Model\ResourceModel\Eav\Attribute $attribute)
    {
        $filterClassName = $this->filterTypes[self::ATTRIBUTE_FILTER];

        if ($attribute->getAttributeCode() == 'price') {
            $filterClassName = $this->filterTypes[self::PRICE_FILTER];
        } elseif ($attribute->getBackendType() == 'decimal') {
            $filterClassName = $this->filterTypes[self::DECIMAL_FILTER];
        }

        return $filterClassName;
    }

    /**
     * Toanhandsome da them doan code nay
     */

    public function getCurrentStore()
    {
        return $this->_storeManager->getStore();
    }

    public function getCurrentCategory()
    {
        $category = $this->registry->registry('current_category');
        if (!$category) {
            $category = $this->categoryRepository->get($this->getCurrentStore()->getRootCategoryId());
        }
        return $category;
    }

    public function getFilterableFeatures()
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        $category =  $this->getCurrentCategory();
        $categoryId = $category->getEntityId();
        $tCategor = $this->_resource->getTableName('sinch_categories');
        $tCatFeature = $this->_resource->getTableName('sinch_categories_features');
        $tRestrictedVal = $this->_resource->getTableName('sinch_restricted_values');
        $tCategMapp = $this->_resource->getTableName('sinch_categories_mapping');

        $connection = $this->_resource->getConnection();

        $select = $connection->select()
            ->from(array('cf' => $tCatFeature))
            ->joinInner(
                array('rv' => $tRestrictedVal),
                'cf.category_feature_id = rv.category_feature_id'
            )
            ->joinInner(
                array('cm' => $tCategMapp),
                'cf.store_category_id = cm.store_category_id'
            )
            ->where('cm.shop_entity_id = '.$categoryId)
            ->group('cf.feature_name')
            ->order('cf.display_order_number', 'asc')
            ->order('cf.feature_name', 'asc')
            ->order('rv.display_order_number', 'asc')
            ->columns('cf.feature_name AS name')
            ->columns('cf.category_feature_id as feature_id')
            ->columns('GROUP_CONCAT(`rv`.`text` SEPARATOR "\n") as restricted_values');

        $result = $connection->fetchAll($select);
        \Magento\Framework\Profiler::stop(__METHOD__);

        return $result;
    }
}
