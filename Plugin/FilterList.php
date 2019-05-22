<?php
namespace SITC\Sinchimport\Plugin;

class FilterList
{
    private $moduleManager;
    private $layerResolver;
    private $resourceConn;
    private $helper;

    private $filterCategoryTable;

    public function __construct(
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Helper\Data $helper
    )
    {
        $this->moduleManager = $moduleManager;
        $this->layerResolver = $layerResolver;
        $this->resourceConn = $resourceConn;
        $this->helper = $helper;

        $this->filterCategoryTable = $resourceConn->getTableName('sinch_filter_categories');
    }

    public function afterGetFilters(\Magento\Catalog\Model\Layer\FilterList $subject, $result)
    {
        //If smile/elasticsuite is installed and enabled, only touch the filters if "Override ElasticSuite" is on
        if ($this->moduleManager->isEnabled('Smile_ElasticsuiteCatalog') &&
            $this->helper->getStoreConfig('sinchimport/attributes/override_elasticsuite') != 1){
            return $result;
        }

        $currentCategory = $this->layerResolver->get()->getCurrentCategory();
        if(empty($currentCategory) || empty($currentCategory->getId())){
            //Not a category, ignore
            return $result;
        }

        $sinch_feature_ids = $this->getConnection()->fetchCol(
            "SELECT feature_id FROM {$this->filterCategoryTable} WHERE category_id = :category_id",
            [":category_id" => $currentCategory->getStoreCategoryId()] //The sinch id (badly named)
        );

        foreach($result as $idx => $abstractFilter) {
            $attributeCode = $abstractFilter->getRequestVar();
            if (strpos($attributeCode, \SITC\Sinchimport\Model\Import\Attributes::ATTRIBUTE_PREFIX) !== 0){
                //Not a sinch attribute
                continue;
            }

            $sinch_id = substr($attributeCode, strlen(\SITC\Sinchimport\Model\Import\Attributes::ATTRIBUTE_PREFIX));

            //If $sinch_feature_ids doesn't contain this filters sinch id, remove it from the results
            if(!in_array($sinch_id, $sinch_feature_ids)) {
                unset($result[$idx]);
            }
        }

        return array_values($result);
    }

    private function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }
}