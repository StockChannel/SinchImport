<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Model\ResourceModel\Product\Attribute;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Model\Import\Attributes;
use Smile\ElasticsuiteCatalog\Search\Request\Product\Aggregation\Provider\FilterableAttributes\Modifier\Coverage;

/**
 * This class implements compatibility for the Sinch filters to be excluded from Elasticsuite 2.8.x's "Facet Min Coverage"
 */
class Coverage28 {
    /** @var Data $helper */
    private $helper;

    public function __construct(
        Data $helper
    ){
        $this->helper = $helper;
    }

    /**
     * Makes sure that Elasticsearch doesn't exclude Sinch filters
     * The original method also accepts $query, $filters and $queryFilters, which we dont use
     * 
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param Coverage $subject
     * @param Attribute[] $result The original result
     * @param int $storeId
     * @param string $requestName
     * @param Attribute[] $attributes
     * @return Attribute[]
     */
    public function afterModifyAttributes(
        Coverage $subject,
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
            if(strpos($attribute->getAttributeCode(), Attributes::ATTRIBUTE_PREFIX) === 0 &&
                !in_array($attribute, $result)) {
                $result[] = $attribute;
            }
        }

        return $result;
    }
}
