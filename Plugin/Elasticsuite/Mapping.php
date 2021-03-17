<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Smile\ElasticsuiteCore\Api\Index\MappingInterface;
use Smile\ElasticsuiteCore\Api\Search\ContextInterface;


class Mapping {

    const CATEGORY_SEARCH_FIELD = 'category.name';
    const CATEGORY_SEARCH_WEIGHT = 8;

    /** @var ContextInterface $searchContext */
    private $searchContext;


    public function __construct(
        ContextInterface $searchContext
    ){
        $this->searchContext = $searchContext;
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
        $catMapping = [ 
            self::CATEGORY_SEARCH_FIELD => $boost * self::CATEGORY_SEARCH_WEIGHT 
        ];
        $result = array_merge($result, $catMapping);
        return $result;
    }
}