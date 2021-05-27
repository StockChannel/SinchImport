<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Smile\ElasticsuiteCore\Api\Index\MappingInterface;
use Smile\ElasticsuiteCore\Api\Search\ContextInterface;
use SITC\Sinchimport\Helper\Data;

class Mapping {

    const CATEGORY_SEARCH_FIELD = 'category.name';
    private $categorySearchWeight;

    const BRAND_SEARCH_FIELD = 'option_text_manufacturer';
    private $brandSearchWeight;

    /** @var ContextInterface $searchContext */
    private $searchContext;

    /** @var Data $helper */
    private $helper;




    public function __construct(
        ContextInterface $searchContext,
        Data $helper
    ){
        $this->searchContext = $searchContext;
        $this->helper = $helper;
        $this->categorySearchWeight = $this->helper->getStoreConfig('sinchimport/search/category_field_search_weight');
        $this->brandSearchWeight = $this->helper->getStoreConfig('sinchimport/search/brand_field_search_weight');
    }

    /**
     * Add category boost to ES mapping
     *
     * @param \Smile\ElasticsuiteCore\Api\Index\MappingInterface $_subject
     * @param float[] $result
     * @param null $analyzer
     * @param null $defaultField
     * @param int $boost
     * @return float[]
     */
    public function afterGetWeightedSearchProperties(MappingInterface $_subject, $result, $analyzer = null, $defaultField = null, $boost = 1)
    {
        if (empty($this->searchContext->getCurrentSearchQuery())) {
            return $result;
        }
        $catMapping = [ 
            self::CATEGORY_SEARCH_FIELD => $boost * $this->categorySearchWeight
        ];
        $brandMapping = [
            self::BRAND_SEARCH_FIELD => $boost * $this->brandSearchWeight
        ];
        $result = array_merge($result, $catMapping, $brandMapping);
        
        return $result;
    }
}