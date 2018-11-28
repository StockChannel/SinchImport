<?php
namespace SITC\Sinchimport\Plugin;

class FilterList
{
    private $moduleManager;
    private $layerResolver;

    public function __construct(
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver
    )
    {
        $this->moduleManager = $moduleManager;
        $this->layerResolver = $layerResolver;
    }

    public function afterGetFilters(\Magento\Catalog\Model\Layer\FilterList $subject, $result)
    {
        //If smile/elasticsuite is installed and enabled, don't touch the list of filters (as it already does similar)
        if ($this->moduleManager->isEnabled('Smile_ElasticsuiteCatalog')) {
            return $result;
        }

        $currentCategory = $this->layerResolver->get()->getCurrentCategory();
        if(empty($currentCategory) || empty($currentCategory->getId())){
            //Not a category, ignore
            return $result;
        }

        $catSaleableProds = $currentCategory->getProductCollection()->count();

        foreach($result as $idx => $abstractFilter) {
            $attributeCode = $abstractFilter->getAttributeModel()->getName();
            if (strpos($attributeCode, \SITC\Sinchimport\Model\Import\Attributes::ATTRIBUTE_PREFIX) != 0){
                //Not a sinch attribute
                continue;
            }

            $productsAffected = 0;
            $filterItems = $abstractFilter->getItems();
            foreach($filterItems as $filterItem){
                $productsAffected += $filterItem->getCount();
            }

            //If filter has 1 item or affects less than 50% of products in the category, remove the filter
            //TODO: Configurable percentage
            if(count($filterItems) < 2 || ($productsAffected / $cat_saleable_products) <= 0.5){
                unset($result[$idx]);
            }
        }

        return array_values($result);
    }
}