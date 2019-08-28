<?php
namespace SITC\Sinchimport\Plugin;

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

class Elasticsuite {
    /**
     * @var \SITC\Sinchimport\Helper\Data
     */
    private $helper;
    private $registry;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory
    ){
        $this->helper = $helper;
        $this->queryFactory = $queryFactory;
    }

    /**
     * Initialize product collection
     *
     * @param \Magento\Search\Model\SearchEngine $subject
     * @param \Magento\Framework\Api\Search\SearchCriteriaInterface $searchCriteria
     */
    public function beforeSearch($subject, $searchCriteria)
    {
        if(!$this->helper->isProductVisiblityEnabled()) return null;
        
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/test.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info(print_r($searchCriteria, true));
        return null;
    }

    private function buildEsQuery()
    {
        $topLevelBool = $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            [
                'should' => [
                    $this->buildBlacklistCondition(),
                    $this->buildWhitelistCondition(),
                    $this->buildNotPresentCondition()
                ]
            ]
        );

        return $this->queryFactory->create(
            QueryInterface::TYPE_FILTERED,
            ['filter' => $topLevelBool] //Not specifying a "must" clause causes the filter to be constant_score
        );
    }

    private function buildBlacklistCondition()
    {
        $partOne = $this->queryFactory->create(
            QueryInterface::TYPE_BOOL, //TODO: Query type for prefix?
            ['prefix' => ['sinch_restrict' => '!']]
        );


        $partTwo = $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            ['must_not' => []] //TODO: Add script condition
        );
        // "script": {
        //     "script": {
        //         "source": """Arrays.asList(/,/.split(doc['sinch_restrict'].value.replace("!", ""))).contains(params.group_id)""",
        //         "params": {
        //             "group_id": "2518"
        //         }
        //     }
        // }

        return $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            [
                'must' => [
                    $partOne,
                    $partTwo
                ]
            ]
        );
    }

    private function buildWhitelistCondition()
    {
        $prefix = $this->queryFactory->create(
            QueryInterface::TYPE_TERM, //TODO: Query type for prefix?
            ['prefix' => ['sinch_restrict' => '!']]
        );

        $partOne = $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            ['must_not' => [$prefix]]
        );

        $partTwo = $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            ['must' => []] //TODO: Add script condition
        );
        // "script": {
        //     "script": {
        //       "source": "Arrays.asList(/,/.split(doc['sinch_restrict'].value)).contains(params.group_id)",
        //       "params": {
        //         "group_id": "2518"
        //       }
        //     }
        //   }

        return $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            [
                'must' => [
                    $partOne,
                    $partTwo
                ]
            ]
        );
    }

    private function buildNotPresentCondition()
    {
        $exists = $this->queryFactory->create(
            QueryInterface::TYPE_EXISTS,
            ['field' => 'sinch_restrict']
        );

        return $this->queryFactory->create(
            QueryInterface::TYPE_BOOL,
            ['must_not' => $exists]
        );
    }
}