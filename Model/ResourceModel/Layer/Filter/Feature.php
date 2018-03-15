<?php

namespace SITC\Sinchimport\Model\ResourceModel\Layer\Filter;

/**
 * SITC Layer Feature Filter resource model
 */
class Feature extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    protected $_lastResultTable;
    
    /**
     * Core event manager proxy
     *
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager = null;
    
    /**
     * @var \Magento\Catalog\Model\Layer
     */
    private $layer;
    
    /**
     * @var \Magento\Customer\Model\Session
     */
    private $session;
    
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;
    
    /**
     * @var \Magento\Framework\App\DeploymentConfig
     */
    private $_deploymentConfig;
    
    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Event\ManagerInterface         $eventManager
     * @param \Magento\Catalog\Model\Layer\Resolver             $layerResolver
     * @param \Magento\Customer\Model\Session                   $session
     * @param \Magento\Store\Model\StoreManagerInterface        $storeManager
     * @param string                                            $connectionName
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver,
        \Magento\Customer\Model\Session $session,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\DeploymentConfig $deploymentConfig,
        $connectionName = null
    ) {
        $this->layer             = $layerResolver->get();
        $this->session           = $session;
        $this->storeManager      = $storeManager;
        $this->_eventManager     = $eventManager;
        $this->_deploymentConfig = $deploymentConfig;
        parent::__construct($context, $connectionName);
    }
    
    /**
     * Apply attribute filter to product collection
     *
     * @param \SITC\Sinchimport\Model\Layer\Filter\Feature $filter
     * @param string                                       $value
     *
     * @return \SITC\Sinchimport\Model\ResourceModel\Layer\Filter\Feature
     */
    public function applyFilterToCollection($filter, $value)
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        
        $searchTable            = $this->_prepareSearch($filter, $value);
        $this->_lastResultTable = $searchTable;
        
        $collection = $filter->getLayer()->getProductCollection();
        
        $feature     = $filter->getFeatureModel();
        $featureName = $feature['feature_name'];
        
        $collection->getSelect()->join(
            $searchTable,
            "{$searchTable}.entity_id = e.entity_id AND {$searchTable}.feature_value = '$value' AND {$searchTable}.feature_name = '{$featureName}'",
            []
        );
        
        \Magento\Framework\Profiler::stop(__METHOD__);
        
        return $this;
    }
    
    /**
     * Подготавливает фильтр к поиску
     *
     * @param \SITC\Sinchimport\Model\Layer\Filter\Feature $filter
     * @param string                                       $value
     *
     * @return string
     */
    protected function _prepareSearch($filter, $value = null)
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        
        $configData = $this->_deploymentConfig->getConfigData();
        
        $catId      = $filter->getLayer()->getCurrentCategory()->getId();
        $connection = $this->getConnection();
        
        $cfid = 0;
        if (! is_null($value)) {
            $feature = $filter->getFeatureModel();
            $cfid    = $feature['category_feature_id'];
        }
        $resultTable = $connection->getTableName(
            'sinch_filter_result_' . $cfid
        );
        
        // check result table exist
        $result = $connection->fetchCol("SHOW TABLES LIKE '$resultTable'");
        if ($result) {
            return $resultTable;
        }
        
        //TODO: this table must be temporary
        $sql
            = "
        CREATE TABLE IF NOT EXISTS `{$resultTable}`(
            `entity_id` int(10) unsigned,
            `category_id` int(10) unsigned,
            `product_id` int,
            `sinch_category_id` int,
            `name` varchar(255),
            `image` varchar(255),
            `supplier_id` int,
            `category_feature_id` int,
            `feature_id` int,
            `feature_name` varchar(255),
            `feature_value` varchar(255),
            KEY `IDX_{$resultTable}_ENTITY_ID` (`entity_id`),
            KEY `IDX_{$resultTable}_FEATURE_NAME` (`feature_name`),
            KEY `IDX_{$resultTable}_FEATURE_VALUE` (`feature_value`)
        );
            ";
        $connection->query($sql);
        
        $addUniqueSql
            = "ALTER TABLE `{$resultTable}` ADD UNIQUE KEY (`entity_id`, `feature_id`, `feature_value`);";
        $connection->query($addUniqueSql);
        
        $sql = "TRUNCATE TABLE {$resultTable}";
        $connection->query($sql);
        
        $featuresTable = $connection->getTableName('sinch_features_list');
        $sql           = "TRUNCATE TABLE `$featuresTable`";
        $connection->query($sql);
        
        $feature = $filter->getFeatureModel();
        if (! isset($feature['limit_direction'])
            || ($feature['limit_direction'] != 1
            && $feature['limit_direction'] != 2)
        ) {
            if (! is_null($value)) {
                $sql
                     = "INSERT INTO `$featuresTable` (category_feature_id, feature_value) VALUES (?)";
                $sql = $connection->quoteInto($sql, [$cfid, $value]);
                $connection->query($sql);
            }
            $params = 'null, null';
        } else {
            $bounds = explode(',', $value);
            
            $params = $bounds[0] != '-' ? (int)$bounds[0] : 'null';
            $params .= ', ';
            $params .= $bounds[1] != '-' ? (int)$bounds[1] : 'null';
        }
        
        $tablePrefix = $configData['db']['table_prefix'];
        $connection->rawQuery(
            "CALL " . $connection->getTableName('sinch_filter_products')
            . "($cfid, $catId,0, $cfid, $params, '$tablePrefix')"
        );
        
        \Magento\Framework\Profiler::stop(__METHOD__);
        
        return $resultTable;
    }
    
    /**
     * Retrieve array with products counts per attribute option
     *
     * @param \SITC\Sinchimport\Model\Layer\Filter\Feature $filter
     *
     * @return array
     */
    public function getCount($filter)
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        
        // clone select from collection with filters
        $select = clone $filter->getLayer()->getProductCollection()->getSelect(
        );
        
        // reset columns, order and limitation conditions
        $select->reset(\Magento\Framework\DB\Select::COLUMNS);
        $select->reset(\Magento\Framework\DB\Select::ORDER);
        $select->reset(\Magento\Framework\DB\Select::LIMIT_COUNT);
        $select->reset(\Magento\Framework\DB\Select::LIMIT_OFFSET);
        
        $connection = $this->getConnection();
        $feature    = $filter->getFeatureModel();
        $featureId  = $feature['category_feature_id'];
        
        $select->joinInner(
            ['sp' => $connection->getTableName('sinch_products')],
            "sp.store_product_id = e.store_product_id",
            []
        )->joinInner(
            ['spf' => $connection->getTableName('sinch_product_features')],
            "spf.sinch_product_id = e.sinch_product_id",
            []
        )->joinInner(
            ['srv' => $connection->getTableName(
                'sinch_restricted_values'
            )],
            "spf.restricted_value_id = srv.restricted_value_id AND srv.category_feature_id = $featureId",
            ['value' => 'srv.text',
                  'count' => 'COUNT(DISTINCT e.entity_id)']
        )
            ->group("srv.text");
        
        \Magento\Framework\Profiler::stop(__METHOD__);
        
        return $connection->fetchPairs($select);
    }
    
    public function getIntervalsCount($filter, $interval)
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        
        // clone select from collection with filters
        $select = clone $filter->getLayer()->getProductCollection()->getSelect(
        );
        
        // reset columns, order and limitation conditions
        $select->reset(\Magento\Framework\DB\Select::COLUMNS);
        $select->reset(\Magento\Framework\DB\Select::ORDER);
        $select->reset(\Magento\Framework\DB\Select::LIMIT_COUNT);
        $select->reset(\Magento\Framework\DB\Select::LIMIT_OFFSET);
        
        $connection = $this->getConnection();
        $feature    = $filter->getFeatureModel();
        $select->joinInner(
            ['spf' => $connection->getTableName('sinch_product_features')],
            "spf.sinch_product_id = e.sinch_product_id",
            []
        )->joinLeft(
            ['srv' => $connection->getTableName(
                'sinch_restricted_values'
            )],
            "srv.restricted_value_id = spf.restricted_value_id",
            ['value' => 'srv.text']
        )->joinLeft(
            ['scf' => $connection->getTableName(
                'sinch_categories_features'
            )],
            "scf.category_feature_id = srv.category_feature_id",
            []
        )->joinLeft(
            ['scm' => $connection->getTableName(
                'sinch_categories_mapping'
            )],
            "scm.shop_store_category_id = scf.store_category_id",
            ['count' => "COUNT(DISTINCT e.entity_id)"]
        )
            ->where(
                'srv.category_feature_id = ?',
                $feature['category_feature_id']
            );
        
        if (isset($interval['low'], $interval['high'])) {
            $select->where('CAST(srv.text AS SIGNED) >= ?', $interval['low'])
                ->where('CAST(srv.text AS SIGNED) < ?', $interval['high']);
        } elseif (isset($interval['low'])) {
            $select->where('CAST(srv.text AS SIGNED) >= ?', $interval['low']);
        } elseif (isset($interval['high'])) {
            $select->where('CAST(srv.text AS SIGNED) < ?', $interval['high']);
        }
        $count = $connection->fetchOne($select);
        
        \Magento\Framework\Profiler::stop(__METHOD__);
        
        return $count;
    }
    
    /**
     * Initialize connection and define main table name
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('sinch_features_list', 'category_feature_id');
    }
    
    /**
     * Retrieve joined price index table alias
     *
     * @return string
     */
    protected function _getIndexTableAlias()
    {
        return 'feature_index';
    }
}
