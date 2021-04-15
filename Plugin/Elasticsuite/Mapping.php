<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Smile\ElasticsuiteCore\Api\Index\MappingInterface;
use Smile\ElasticsuiteCore\Api\Search\ContextInterface;
use SITC\Sinchimport\Helper\Data;

class Mapping {

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
        $this->brandSearchWeight = $this->helper->getStoreConfig('sinchimport/search/brand_field_search_weight');
    }

    /**
     * Add category boost to ES mapping
     *
     * @param \Smile\ElasticsuiteCore\Api\Index\MappingInterface $_subject
     * @param float[] $result
     *
     * @return float[]
     */
    public function afterGetWeightedSearchProperties(MappingInterface $_subject, $result, $analyzer = null, $defaultField = null, $boost = 1)
    {
        if (empty($this->searchContext->getCurrentSearchQuery())) {
            return $result;
        }
        $brandMapping = [
            self::BRAND_SEARCH_FIELD => $boost * $this->brandSearchWeight
        ];
        $result = array_merge($result, $brandMapping);
        
        return $result;
    }
}