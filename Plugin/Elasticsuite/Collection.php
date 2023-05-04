<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Search\Request\Query\QueryFactory;

class Collection
{
    private Data $helper;
    private QueryFactory $queryFactory;

    public function __construct(Data $helper, QueryFactory $queryFactory)
    {
        $this->helper = $helper;
        $this->queryFactory = $queryFactory;
    }

    public function beforeLoad(\Smile\ElasticsuiteCatalog\Model\ResourceModel\Product\Fulltext\Collection $subject, $printQuery = false, $logQuery = false)
    {
        if ($this->helper->isProductVisibilityEnabled()) {
            $subject->addQueryFilter($this->queryFactory->create(
                'sitcAccountGroupQuery',
                ['account_group' => $this->helper->getCurrentAccountGroupId()]
            ));
        }
        return null;
    }
}