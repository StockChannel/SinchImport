<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;


use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\SearchProcessing;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\Builder as QueryBuilder;

class Provider
{
    private $logger;
    private Data $helper;
    private SearchProcessing $searchHelper;
    private QueryFactory $queryFactory;

    /**
     * Provider constructor.
     *
     * @param Data $helper
     * @param QueryFactory $queryFactory
     * @param SearchProcessing $searchHelper
     */
    public function __construct(Data $helper, QueryFactory $queryFactory, SearchProcessing $searchHelper)
    {
        $writer = new Stream(BP . '/var/log/joe_search_stuff.log');
        $logger = new Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;

        $this->helper = $helper;
        $this->searchHelper = $searchHelper;
        $this->queryFactory = $queryFactory;
    }

    /**
     * @param QueryBuilder $subject
     * @param QueryInterface $result
     *
     * @return QueryInterface
     */
    public function afterCreateQuery(
        QueryBuilder $subject,
        QueryInterface $result
    ){
        $boostQuery = $this->searchHelper->getBoostQuery();
        if ($boostQuery == null) {
            return $result;
        }

        return $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            [
                'must' => [$result],
                'should' => [$boostQuery],
                'minimumShouldMatch' => 0,
                'boost' => 1
            ]
        );
    }
}