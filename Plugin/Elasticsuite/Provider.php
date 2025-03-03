<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;


use Monolog\Handler\ChromePHPHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
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
        $this->helper = $helper;
        $this->searchHelper = $searchHelper;
        $this->queryFactory = $queryFactory;

        $this->logger = new Logger("es_provider");
        $this->logger->pushHandler(new FirePHPHandler());
        $this->logger->pushHandler(new ChromePHPHandler());
        if ($this->helper->getStoreConfig('sinchimport/general/debug') != 1) {
            $this->logger->pushHandler(new NullHandler());
        }
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
        $this->logger->info("Adding boost to created query");

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