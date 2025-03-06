<?php
namespace SITC\Sinchimport\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Model\Import\Attributes;

class FilterList
{
    private $moduleManager;
    private $resourceConn;
    private $helper;

    private $filterCategoryTable;

    public function __construct(
        Manager $moduleManager,
        ResourceConnection $resourceConn,
        Data $helper
    ){
        $this->moduleManager = $moduleManager;
        $this->resourceConn = $resourceConn;
        $this->helper = $helper;

        $this->filterCategoryTable = $resourceConn->getTableName('sinch_filter_categories');
    }

    public function afterGetFilters(\Magento\Catalog\Model\Layer\FilterList $subject, $result, \Magento\Catalog\Model\Layer $layer)
    {
        //If smile/elasticsuite is installed and enabled, only touch the filters if "Override ElasticSuite" is on
        if ($this->moduleManager->isEnabled('Smile_ElasticsuiteCatalog') &&
            $this->helper->getStoreConfig('sinchimport/attributes/override_elasticsuite') != 1){
            return $result;
        }

        $currentCategory = $layer->getCurrentCategory();
        if(empty($currentCategory) || empty($currentCategory->getId())){
            //Not a category, ignore
            return $result;
        }

        $sinch_cat_id = $currentCategory->getStoreCategoryId(); //The sinch id (badly named)
        if(empty($sinch_cat_id)) {
            //We're being called from ElasticSuite's AJAX filter list route (we can tell because normally the Sinch ID would already be populated)
            $catalog_category_entity = $this->resourceConn->getTableName('catalog_category_entity');
            $sinch_cat_id = $this->getConnection()->fetchOne(
                "SELECT store_category_id FROM {$catalog_category_entity} WHERE entity_id = :catId",
                [":catId" => $currentCategory->getId()]
            );
        }

        $sinch_feature_ids = $this->getConnection()->fetchCol(
            "SELECT feature_id FROM {$this->filterCategoryTable} WHERE category_id = :category_id",
            [":category_id" => $sinch_cat_id]
        );

        foreach($result as $idx => $abstractFilter) {
            $attributeCode = $abstractFilter->getRequestVar();
            if (strpos($attributeCode, Attributes::ATTRIBUTE_PREFIX) !== 0){
                //Not a sinch attribute
                continue;
            }

            $sinch_id = substr($attributeCode, strlen(Attributes::ATTRIBUTE_PREFIX));

            //If $sinch_feature_ids doesn't contain this filters sinch id, remove it from the results
            if(!in_array($sinch_id, $sinch_feature_ids)) {
                unset($result[$idx]);
            }
        }

        return array_values($result);
    }

    private function getConnection()
    {
        return $this->resourceConn->getConnection(ResourceConnection::DEFAULT_CONNECTION);
    }
}
