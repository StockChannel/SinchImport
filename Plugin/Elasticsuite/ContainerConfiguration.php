<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Api\Search\ContextInterface;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

class ContainerConfiguration {
    /** @var Data $helper */
    private $helper;
    /** @var QueryFactory $queryFactory */
    private $queryFactory;
    /** @var ContextInterface $searchContext */
    private $searchContext;


    public function __construct(
        Data $helper,
        QueryFactory $queryFactory,
        ContextInterface $searchContext
    ){
        $this->helper = $helper;
        $this->queryFactory = $queryFactory;
        $this->searchContext = $searchContext;
    }

    /**
     * Add the account group filter for custom catalog
     * to the filter list if product visibility is enabled
     *
     * @param ContainerConfigurationInterface $_subject The intercepted class
     * @param QueryInterface[] $result The return of the intercepted method
     *
     * @return QueryInterface[]
     */
    public function afterGetFilters(ContainerConfigurationInterface $_subject, array $result): array
    {
        if ($this->helper->isProductVisibilityEnabled()) {
            $result[] = $this->queryFactory->create(
                'sitcAccountGroupQuery',
                ['account_group' => $this->helper->getCurrentAccountGroupId()]
            );
        }
        return $result;
    }
}