<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\SearchProcessing;
use SITC\Sinchimport\Search\Request\Query\PriceRangeQuery;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\FunctionScore;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Zend\Log\Logger;
use Zend\Log\Writer\Stream;

class QueryBuilder
{

	const PRICE_REGEXP = "/(?(DEFINE)(?<price>[0-9]+(?:.[0-9]+)?)(?<cur>(?:\p{Sc}|[A-Z]{3})\s?))(?<query>.+?)\s+(?J:(?:below|under|(?:cheaper|less)\sthan)\s+(?&cur)?(?<below>(?&price))|(?:between|from)?\s*(?&cur)?(?<above>(?&price))\s*(?:and|to|-)\s*(?&cur)?(?<below>(?&price)))/u";

	const FILTERABLE_OPTIONS = [ 'manufacturer', 'sinch_family' ];

	const QUERY_TYPE_PRODUCT_AUTOCOMPLETE = 'catalog_product_autocomplete';
	const QUERY_TYPE_QUICKSEARCH = 'quick_search_container';

	/** @var CategoryRepositoryInterface */
	private $categoryRepository;
	/** @var HttpResponse */
	private $response;
	/** @var Logger */
	private $logger;
	private $resourceConnection;
	private $connection;
	private $categoryTableVarchar;
	private $categoryTable;
	private $eavTable;
	private $eavOptionValueTable;
	private $eavOptionTable;
	private $sinchCategoriesTable;
	private $sinchCategoriesMappingTable;
	private $productFamilyTable;
	private $sinchProductsTable;

	/** @var Data */
	private $helper;
	/** @var QueryFactory $queryFactory */
	private $queryFactory;
	/** @var bool $priceFilterMode True if we should be filtering based on price constraints or just boosting */
	private $priceFilterMode = false;
	/** @var Session $customerSession */
	private $customerSession;
	private SearchProcessing $spHelper;


	public function __construct(
		CategoryRepositoryInterface $categoryRepository,
		HttpResponse $response,
		ResourceConnection $resourceConnection,
		Data $helper,
		QueryFactory\Proxy $queryFactory,
		Session\Proxy $customerSession,
        SearchProcessing $spHelper
	)
	{
		$this->categoryRepository = $categoryRepository;
		$this->response = $response;
		$writer = new Stream(BP . '/var/log/joe_search_stuff.log');
		$this->logger = new Logger();
		$this->logger->addWriter($writer);
		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->categoryTableVarchar = $this->connection->getTableName('catalog_category_entity_varchar');
		$this->categoryTable = $this->connection->getTableName('catalog_category_entity');
		$this->eavTable = $this->connection->getTableName('eav_attribute');
		$this->eavOptionTable = $this->connection->getTableName('eav_attribute_option');
		$this->eavOptionValueTable = $this->connection->getTableName('eav_attribute_option_value');
		$this->sinchCategoriesTable = $this->connection->getTableName('sinch_categories');
		$this->sinchCategoriesMappingTable = $this->connection->getTableName('sinch_categories_mapping');
		$this->productFamilyTable = $this->connection->getTableName('sinch_family');
		$this->sinchProductsTable = $this->connection->getTableName('sinch_products');
		$this->helper = $helper;
		$this->queryFactory = $queryFactory;
		$this->customerSession = $customerSession;
		$this->spHelper = $spHelper;
	}

	/**
	 * Intercepts the QueryBuilders create method to perform additional experimental search processing, if enabled
	 *
	 * @param \Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject
	 * @param callable $proceed
	 * @param ContainerConfigurationInterface $containerConfig
	 * @param string|string[] $queryText
	 * @param string $spellingType
	 * @param float $boost
	 * @return QueryInterface
	 * @SuppressWarnings("unused")
	 */
	public function aroundCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, callable $proceed, ContainerConfigurationInterface $containerConfig, $queryText, string $spellingType, float $boost = 1): ?QueryInterface
	{
		if (!$this->helper->experimentalSearchEnabled()) {
			return $proceed($containerConfig, $queryText, $spellingType, $boost);
		}

		//Only attempt to do special handling of the quick search and the quick search's product suggestions (skip category_search_container)
		if ($containerConfig->getName() !== self::QUERY_TYPE_QUICKSEARCH && $containerConfig->getName() !== self::QUERY_TYPE_PRODUCT_AUTOCOMPLETE) {
		    return $proceed($containerConfig, $queryText, $spellingType, $boost);
        }

		//Fix compat with autosuggestions (which for some reason pass $queryText as an array with a single element)
        if (is_array($queryText) && count($queryText) >= 1 && is_string($queryText[0])) {
            $queryText = $queryText[0];
        }

		//TODO: New SearchProcessing use
        //This call can modify query text if one or more filters match
        $queryFilters = $this->spHelper->getFiltersFromQuery($containerConfig, $queryText);

        //Check eav attribute values for FILTERABLE_OPTIONS
        $queryTokens = explode(' ', $queryText);
        $doubleTokens = $this->doubleTokenize($queryText);
		$optionFilters = [];
		foreach (self::FILTERABLE_OPTIONS as $filterOptionCode) {
			$optionValue = $this->getOptionAttributeValue($filterOptionCode, array_merge($queryTokens, $doubleTokens));
			if (!empty($optionValue)) {
				if ($optionValue['attribute_code'] != 'sinch_family')
					$queryText = trim(str_ireplace("{$optionValue['value']}", '', $queryText));
				$optionFilters[] = $optionValue;
			}
		}

		$queryVariants = $this->spHelper->getQueryTextRewrites($containerConfig, $queryText);
		$this->logger->info($queryVariants);

		//If this isn't a product autocomplete suggestion and category match is successful return early
		if ($containerConfig->getName() !== self::QUERY_TYPE_PRODUCT_AUTOCOMPLETE && $this->checkCategoryOrFamilyMatch($containerConfig, $queryVariants, $queryFilters, $optionFilters)) {
			return null;
		}

		//This results in modified query text if the query matched the price regex
		$originalResult = $proceed(
			$containerConfig,
			$queryText,
			$spellingType,
			$boost
		);

		//Pass in a list of synonyms for the category boost
		$shouldClauses = [$this->queryFactory->create('sitcCategoryBoostQuery', ['queries' => $queryVariants])];
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
				$groupId = Group::NOT_LOGGED_IN_ID;
				try {
					$groupId = $this->customerSession->getCustomerGroupId();
				} catch (NoSuchEntityException | LocalizedException $e) {}

				$shouldClauses[] = $this->queryFactory->create('sitcPriceRangeQuery', ['bounds' => $bounds, 'account_group' => $groupId]);
                $minShouldMatch += $this->priceFilterMode ? 1 : 0;
            }
        }

		if ($this->helper->popularityBoostEnabled()) {
		    $shouldClauses[] = $this->queryFactory->create(
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
                        ]
                    ],
                    'scoreMode' => FunctionScore::SCORE_MODE_MAX,
                    'boostMode' => FunctionScore::BOOST_MODE_SUM
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
	 * @param string[] $queries
	 * @param $queryFilters
	 * @param array $optionFilters
	 * @return bool true if matched
	 */
	private function checkCategoryOrFamilyMatch(ContainerConfigurationInterface $containerConfig, array $queries, $queryFilters, array $optionFilters): bool
	{
		$start = microtime(true);

		$inClause = implode(",", array_fill(0, count($queries), '?'));

		//Arrays merged in bind to ensure the number of params matches number of tokens
		$catId = $this->connection->fetchOne(
			"SELECT ccev.entity_id FROM {$this->categoryTableVarchar} ccev 
                JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
                JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id
				JOIN {$this->sinchCategoriesMappingTable} scm ON scm.shop_entity_id = cce.entity_id
				JOIN {$this->sinchCategoriesTable} sc ON sc.store_category_id = scm.store_category_id AND sc.products_within_this_category < 100000
                WHERE ccev.value IN ($inClause) OR sc.VirtualCategory IN ($inClause)",
			array_merge($queries, $queries)
		);

		//Product family match if no category match detected
		if (empty($catId)) {
			$catId = $this->connection->fetchOne(
				"SELECT cce.entity_id FROM {$this->categoryTable} cce
				JOIN {$this->sinchProductsTable} sp ON sp.store_category_id = cce.store_category_id
				JOIN {$this->productFamilyTable} pf ON pf.id = sp.family_id
				WHERE pf.name IN ({$inClause})",
			$queries);
		}

		//Category or virtual category name match, don't bother creating the ES query and instead redirect
		if (!empty($catId)) {
			$filterParams = '';
			if (!empty($queryFilters[SearchProcessing::FILTER_TYPE_PRICE]) && $queryFilters[SearchProcessing::FILTER_TYPE_PRICE] instanceof PriceRangeQuery) {
			    $bounds = $queryFilters[SearchProcessing::FILTER_TYPE_PRICE]->getBounds();
			    if (!empty($bounds['lte']) && is_numeric($bounds['lte'])) {
			        $min = $bounds['gte'] ?? '0';
                    $filterParams = "?price={$min}-{$bounds['lte']}";
                }
            }

			foreach ($optionFilters as $filter) {
				$filterValue = $filter['value'];
				if (!empty($filterValue)) {
					$attributeCode = $filter['attribute_code'];
					$filterParams .= (empty($filterParams) ? "?" : "&") . "{$attributeCode}=" . $filterValue;
				}
			}

			try {
				$category = $this->categoryRepository->get((int)$catId);
				$url = $category->getUrl() . $filterParams;

				$this->response->setRedirect($url)->sendResponse();

				$elapsed = abs(microtime(true) - $start) * 1000;
				$this->logger->info("Total execution time: " . strval($elapsed) . "ms");
				return true;
				//Silently ignore NoSuchEntity, which is very unlikely since we asked the database for the id in the first place
			} catch (NoSuchEntityException $e) {
			}
		}
		return false;
	}

	private function getBrandName(array $queryText): array
	{
		return $this->getOptionAttributeValue('manufacturer', $queryText);
	}

	private function getProductFamily(array $queryText) : array
	{
		return $this->getOptionAttributeValue('sinch_family', $queryText);
	}

	private function getOptionAttributeValue(string $attributeCode, array $queryText) : array
	{
		$inClause = implode(",", array_fill(0, count($queryText), '?'));

		$optionValue = $this->connection->fetchOne(
			"SELECT eaov.value FROM eav_attribute_option_value eaov
				INNER JOIN eav_attribute_option eao ON eao.option_id = eaov.option_id
				INNER JOIN eav_attribute ea ON ea.attribute_id = eao.attribute_id
				WHERE ea.attribute_code = ? AND eaov.value IN ({$inClause}) LIMIT 1",
			array_merge($attributeCode, $queryText)
		);

		return [
			'attribute_code' => $attributeCode,
			'value' => $optionValue
		];
	}

    /**
     * Return all double tokens from the query text
     * @return string[]
     */
	private function doubleTokenize(string $queryText): array
    {
        $queryTokens = explode(' ', $queryText);
        $firstWord = '';
        $doubleTokens = [];
        foreach ($queryTokens as $token) {
            $doubleTokens[] = $firstWord . ' ' . $token;
            $firstWord = $token;
        }
        unset($doubleTokens[0]);
        return $doubleTokens;
    }
}
