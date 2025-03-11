<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Api\Search\ContextInterface;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

class ContainerConfiguration {
    public function __construct(
        private readonly Data         $helper,
        private readonly QueryFactory $queryFactory
    ){}

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