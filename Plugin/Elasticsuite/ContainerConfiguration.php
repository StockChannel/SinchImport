<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;

class ContainerConfiguration {
    /**
     * @var \SITC\Sinchimport\Helper\Data $helper
     */
    private $helper;
    /**
     * @var \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory
     */
    private $queryFactory;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory
    ){
        $this->helper = $helper;
        $this->queryFactory = $queryFactory;
    }

    /**
     * Add the account group filter for custom catalog 
     * to the filter list if product visibility is enabled
     * 
     * @param \Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface $_subject The intercepted class
     * @param \Smile\ElasticsuiteCore\Search\Request\QueryInterface[] $result The return of the intercepted method
     * 
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface[]
     */
    public function afterGetFilters(
        ContainerConfigurationInterface $_subject,
        $result
    ){
        if($this->helper->isProductVisibilityEnabled()) {
            $result[] = $this->queryFactory->create(
                'sitcAccountGroupQuery',
                ['account_group' => $this->helper->getCurrentAccountGroupId()]
            );
        }
        return $result;
    }
}