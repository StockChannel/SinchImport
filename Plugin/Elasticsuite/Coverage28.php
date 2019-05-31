<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

/**
 * This class implements compatibility for the Sinch filters to be excluded from Elasticsuite 2.8.x's "Facet Min Coverage"
 */
class Coverage28 {
    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper
    ){
        $this->helper = $helper;
    }

    /**
     * Makes sure that Elasticsearch doesn't exclude Sinch filters
     * The original method also accepts $query, $filters and $queryFilters, which we dont use
     * 
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param \Smile\ElasticsuiteCatalog\Search\Request\Product\Aggregation\Provider\FilterableAttributes\Modifier\Coverage $subject
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute[] $result The original result
     * @param int $storeId
     * @param string $requestName
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute[] $attributes
     * @return \Magento\Catalog\Model\ResourceModel\Product\Attribute[]
     */
    public function afterModifyAttributes(
        \Smile\ElasticsuiteCatalog\Search\Request\Product\Aggregation\Provider\FilterableAttributes\Modifier\Coverage $subject,
        $result,
        $storeId,
        $requestName,
        $attributes
    ){
        if($this->helper->getStoreConfig('sinchimport/attributes/override_elasticsuite') != 1){
            //Leave ElasticSuite to do its thing
            return $result;
        }

        foreach($attributes as $attribute){
            if(strpos($attribute->getAttributeCode(), \SITC\Sinchimport\Model\Import\Attributes::ATTRIBUTE_PREFIX) === 0 &&
                !in_array($attribute, $result)) {
                $result[] = $attribute;
            }
        }

        return $result;
    }
}