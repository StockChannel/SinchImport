<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\FunctionScore;
use Smile\ElasticsuiteCore\Search\Request\Query\Nested;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteThesaurus\Model\Index;

class QueryBuilder
{

	CONST PRICE_REGEXP = "/(?(DEFINE)(?<price>[0-9]+(?:.[0-9]+)?)(?<cur>(?:\p{Sc}|[A-Z]{3})\s?))(?<query>.+?)\s+(?J:(?:below|under|(?:cheaper|less)\sthan)\s+(?&cur)?(?<below>(?&price))|(?:between|from)?\s*(?&cur)?(?<above>(?&price))\s*(?:and|to|-)\s*(?&cur)?(?<below>(?&price)))/";

    /** @var CategoryRepositoryInterface */
    private $categoryRespository;
    /** @var HttpResponse */
    private $response;
    /** @var \Zend\Log\Logger */
    private $logger;
	private $resourceConnection;
	private $connection;
	private $categoryTableVarchar;
	private $categoryTable;
	private $eavTable;

	/** @var Data */
	private $helper;
	/** @var QueryFactory $queryFactory */
	private $queryFactory;
	/** @var bool $priceFilterMode True if we should be filtering based on price constraints or just boosting */
	private $priceFilterMode = false;
	/** @var Session $customerSession */
    private $customerSession;
    /** @var Index $thesaurus */
    private $thesaurus;

    public function __construct(
		CategoryRepositoryInterface $categoryRespository,
		HttpResponse $response,
		ResourceConnection $resourceConnection,
		Data $helper,
        QueryFactory\Proxy $queryFactory,
        Session\Proxy $customerSession,
        Index $thesaurus
	){
        $this->categoryRespository = $categoryRespository;
        $this->response = $response;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->categoryTableVarchar = $this->connection->getTableName('catalog_category_entity_varchar');
		$this->categoryTable = $this->connection->getTableName('catalog_category_entity');
		$this->eavTable = $this->connection->getTableName('eav_attribute');
		$this->helper = $helper;
		$this->queryFactory = $queryFactory;
		$this->customerSession = $customerSession;
		$this->thesaurus = $thesaurus;
    }

    /**
     * Intercepts the QueryBuilders create method to perform additional experimental search processing, if enabled
     *
     * @param \Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject
     * @param callable $proceed
     * @param ContainerConfigurationInterface $containerConfig
     * @param string $queryText
     * @param string $spellingType
     * @param float $boost
     * @return QueryInterface
     * @SuppressWarnings("unused")
     */
    public function aroundCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, callable $proceed, ContainerConfigurationInterface $containerConfig, string $queryText, string $spellingType, float $boost = 1): ?QueryInterface
    {
		if (!$this->helper->experimentalSearchEnabled()) {
			return $proceed($containerConfig, $queryText, $spellingType, $boost);
		}

		$priceFilter = $this->getPriceFiltersFromQuery($queryText);
		if ($priceFilter !== false) {
		    $queryText = $priceFilter['query'];
        }

		//If category match is successful return early
		if ($this->checkCategoryMatch($containerConfig, $queryText, $priceFilter)) {
		    return null;
        }

		//This results in modified query text if the query matched the price regex
        $originalResult = $proceed(
            $containerConfig,
            $queryText,
            $spellingType,
            $boost
        );

		$shouldClauses = [];
		$minShouldMatch = 0;

		//Check if we should do price filtering/boost
		if ($priceFilter !== false) {
		    $bounds = [];
		    if (!empty($priceFilter['above']) && is_numeric($priceFilter['above'])) {
		        $bounds['gte'] = $priceFilter['above'];
            }
		    if (!empty($priceFilter['below']) && is_numeric($priceFilter['below']) && $priceFilter['below'] != -1) {
		        $bounds['lte'] = $priceFilter['below'];
            }

		    if(!empty($bounds)) {
                $shouldClauses[] = $this->buildPriceRangeQuery($bounds);
                $minShouldMatch += $this->priceFilterMode ? 1 : 0;
            }
        }

		if ($this->helper->popularityBoostEnabled()) {
		    $shouldClauses[] = $this->queryFactory->create(
		        QueryInterface::TYPE_FUNCTIONSCORE,
                [
                    'functions' => [
                        FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                            'field' => '', //TODO: Determine field name
                            'factor' => $this->helper->popularityBoostFactor(),
                            'modifier' => 'none'
                        ]
                    ]
                ]
            );
        }

		if (!empty($shouldClauses)) {
		    return $this->queryFactory->create(
                QueryInterface::TYPE_BOOL,
                [
                    'must' => [$originalResult],
                    'should' => $shouldClauses,
                    'minimumShouldMatch' => $minShouldMatch
                ]
            );
        }

		return $originalResult;
    }


    /**
     * Get price bounds from query text
     *
     * @param string $queryText
     *
     * @return array|bool
     */
	private function getPriceFiltersFromQuery(string $queryText)
	{
		$matches = [];
		if (preg_match_all(self::PRICE_REGEXP, $queryText, $matches, PREG_SET_ORDER)) {
			$matches = $matches[0];
			$query = $matches['query'] ?? '';
			$below = $matches['below'] ?? -1;
			$above = $matches['above'] ?? 0;

            return [
                'query' => $query,
                'below' => $below,
                'above' => $above,
            ];
		}

		return false;
	}

    /**
     * @param ContainerConfigurationInterface $containerConfig
     * @param string $queryText
     * @param $priceFilter
     * @return bool true if matched
     */
	private function checkCategoryMatch(ContainerConfigurationInterface $containerConfig, string $queryText, $priceFilter): bool
    {
        $start = microtime(true);
        $pluralQueryText = $queryText . 's';

        //Process thesaurus rewrites for our query
        $queryVariants = array_merge(
            [$queryText, $pluralQueryText],
            array_keys($this->thesaurus->getQueryRewrites($containerConfig, $queryText)),
            array_keys($this->thesaurus->getQueryRewrites($containerConfig, $pluralQueryText))
        );
        $inClause = implode(",", array_fill(0, count($queryVariants), '?'));

        $catId = $this->connection->fetchOne(
            "SELECT ccev.entity_id FROM {$this->categoryTableVarchar} ccev 
                JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
                JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id
                WHERE ccev.value IN ($inClause)",
            $queryVariants
        );

        //Category name match, don't bother creating the ES query and instead redirect
        if (!empty($catId)) {
            $filterParams = '';
            if ($priceFilter !== false && $priceFilter['below'] != -1) {
                $filterParams = "?price={$priceFilter['above']}-{$priceFilter['below']}";
            }

            try {
                $category = $this->categoryRespository->get((int)$catId);
                $url = $category->getUrl() . $filterParams;

                $this->response->setRedirect($url)->sendResponse();

                $elapsed = abs(microtime(true) - $start) * 1000;
                $this->logger->info("Total execution time: " . strval($elapsed) . "ms");
                return true;
                //Silently ignore NoSuchEntity, which is very unlikely since we asked the database for the id in the first place
            } catch (NoSuchEntityException $e) {}
        }
        return false;
    }

	private function buildPriceRangeQuery(array $bounds): QueryInterface
    {
        $rangeQuery = $this->queryFactory->create(
            QueryInterface::TYPE_RANGE,
            ['field' => 'price.price', 'bounds' => $bounds]
        );

        $groupId = Group::NOT_LOGGED_IN_ID;
        try {
            $groupId = $this->customerSession->getCustomerGroupId();
        } catch (NoSuchEntityException | LocalizedException $e) {}


        $customerGroupQuery = $this->queryFactory->create(
            QueryInterface::TYPE_TERM,
            ['field' => 'price.customer_group_id', 'value' => $groupId]
        );

        return $this->queryFactory->create(
            QueryInterface::TYPE_NESTED,
            [
                'path' => 'price',
                'query' => $this->queryFactory->create(
                    QueryInterface::TYPE_BOOL,
                    ['must' => [$rangeQuery, $customerGroupQuery]]
                ),
                'scoreMode' => Nested::SCORE_MODE_AVG,
                'boost' => 10.0
            ]
        );
    }
}
