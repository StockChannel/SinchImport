<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;


use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Search\Request\Query\FunctionScore;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

class Provider
{
    private $logger;

    private $helper;

    /** @var QueryFactory $queryFactory */
    private $queryFactory;

    /**
     * Provider constructor.
     *
     * @param Data $helper
     * @param QueryFactory $queryFactory
     */
    public function __construct(Data $helper, QueryFactory $queryFactory)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;

        $this->helper = $helper;
        $this->queryFactory = $queryFactory;
    }

    /**
     * @param \Smile\ElasticsuiteCatalog\Model\Category\Filter\Provider $subject
     * @param QueryInterface $result
     *
     * @return QueryInterface
     */
    public function afterGetQueryFilter(
        \Smile\ElasticsuiteCatalog\Model\Category\Filter\Provider $subject,
        QueryInterface $result
    ){
//        $toReturn = $this->queryFactory->create(
//            QueryInterface::TYPE_BOOL,
//            [
//                'must' => [$result],
//                'should' => $this->getBoostQuery(),
//                'minimumShouldMatch' => 0
//            ]
//        );
        return $result;
    }


    /**
     * @return QueryInterface|null
     */
    private function getBoostQuery(): ?QueryInterface
    {
        if ($this->helper->popularityBoostEnabled()) {
            return $this->queryFactory->create(
                QueryInterface::TYPE_FUNCTIONSCORE,
                [
                    'query' => $this->queryFactory->create(QueryInterface::TYPE_FILTER), //Filtered with no args is a match_all
                    'functions' => [
                        [ //Boost on Popularity Score
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_score', //TODO: Seems like field names for non-option int attributes are just their attribute code, confirm
                                'factor' => $this->helper->popularityBoostFactor(),
                                'modifier' => 'log1p',
                                'missing' => 0
                            ],
                            'weight' => 5
                        ],
                        [ //Boost on Monthly BI data
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_popularity_month',
                                'factor' => $this->helper->monthlyPopularityBoostFactor(),
                                'modifier' => 'log1p',
                                'missing' => 0
                            ],
                            'weight' => 10
                        ],
                        [ //Boost on Yearly BI data
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_popularity_year',
                                'factor' => $this->helper->yearlyPopularityBoostFactor(),
                                'modifier' => 'log1p',
                                'missing' => 0
                            ],
                            'weight' => 8
                        ],
                        [ //Boost on sinch search data
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_searches',
                                'factor' => $this->helper->searchesBoostFactor(),
                                'modifier' => 'log1p',
                                'missing' => 0
                            ],
                            'weight' => 8
                        ]
                    ],
                    'scoreMode' => FunctionScore::SCORE_MODE_MAX,
                    'boostMode' => FunctionScore::BOOST_MODE_SUM
                ]
            );
        }
        return null;
    }
}