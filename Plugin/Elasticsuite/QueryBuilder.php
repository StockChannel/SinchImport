<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\SchemaSetupInterface;
use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteThesaurus\Model\Index;
use Zend\Log\Logger;
use Zend\Log\Writer\Stream;

class QueryBuilder
{

	const PRICE_REGEXP = "/(?(DEFINE)(?<price>[0-9]+(?:.[0-9]+)?)(?<cur>(?:\p{Sc}|[A-Z]{3})\s?))(?<query>.+?)\s+(?J:(?:below|under|(?:cheaper|less)\sthan)\s+(?&cur)?(?<below>(?&price))|(?:between|from)?\s*(?&cur)?(?<above>(?&price))\s*(?:and|to|-)\s*(?&cur)?(?<below>(?&price)))/u";

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
		CategoryRepositoryInterface $categoryRepository,
		HttpResponse $response,
		ResourceConnection $resourceConnection,
		Data $helper,
		QueryFactory\Proxy $queryFactory,
		Session\Proxy $customerSession,
		Index $thesaurus
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

		$queryTokens = explode(' ', $queryText);
		$firstWord = '';
		$doubleTokens = [];
		foreach ($queryTokens as $token) {
			$doubleTokens[] = $firstWord . ' ' . $token;
			$firstWord = $token;
		}
		unset($doubleTokens[0]);

		$brandName = $this->isBrandName(array_merge($queryTokens, $doubleTokens));
		if (!empty($brandName)) {
			//Trim query text to ensure the category matches
			$queryText = trim(str_ireplace("{$brandName}", '', $queryText));
		}

		$pluralQueryText = $queryText . 's';
		//Process thesaurus rewrites for our query
		$queryVariants = array_values(array_unique(array_merge(
			[$queryText, $pluralQueryText],
			array_keys($this->thesaurus->getQueryRewrites($containerConfig, $queryText)),
			array_keys($this->thesaurus->getQueryRewrites($containerConfig, $pluralQueryText))
		)));
		

		//Pluralise each of the synonyms in thesaurus to prevent having to add plural versions to dictionary
		foreach ($queryVariants as $queryVariant) {
			$queryVariants[] = $queryVariant . 's';
		}
		$this->logger->info($queryVariants);

		//If category match is successful return early
		if ($this->checkCategoryMatch($containerConfig, $queryVariants, $priceFilter, $brandName)) {
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
		$should = [$this->queryFactory->create('sitcCategoryBoostQuery', ['queries' => $queryVariants])];

		if ($priceFilter !== false) {
			$bounds = [];
			if (!empty($priceFilter['above']) && is_numeric($priceFilter['above'])) {
				$bounds['gte'] = $priceFilter['above'];
			}
			if (!empty($priceFilter['below']) && is_numeric($priceFilter['below']) && $priceFilter['below'] != -1) {
				$bounds['lte'] = $priceFilter['below'];
			}

			if (empty($bounds)) {
				return $originalResult;
			}

			$groupId = Group::NOT_LOGGED_IN_ID;
			try {
				$groupId = $this->customerSession->getCustomerGroupId();
			} catch (NoSuchEntityException | LocalizedException $e) {
			}

			$should[] = $this->queryFactory->create('sitcPriceRangeQuery',
				['bounds' => $bounds, 'account_group' => $groupId]);
		}


		return $this->queryFactory->create(
			QueryInterface::TYPE_BOOL,
			[
				'must' => [$originalResult],
				'should' => $should,
				'minimumShouldMatch' => $this->priceFilterMode ? 1 : 0
			]
		);
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
			$query = isset($matches['query']) ? $matches['query'] : '';
			$below = isset($matches['below']) ? $matches['below'] : -1;
			$above = isset($matches['above']) ? $matches['above'] : 0;

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
	 * @param $priceFilter
	 * @param $brandFilter
	 * @return bool true if matched
	 */
	private function checkCategoryMatch(ContainerConfigurationInterface $containerConfig, array $queries, $priceFilter, $brandFilter): bool
	{
		$start = microtime(true);

		$inClause = implode(",", array_fill(0, count($queries), '?'));

		$catId = $this->connection->fetchOne(
			"SELECT ccev.entity_id FROM {$this->categoryTableVarchar} ccev 
                JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
                JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id
                WHERE ccev.value IN ($inClause)",
			$queries
		);

		//Category name match, don't bother creating the ES query and instead redirect
		if (!empty($catId)) {
			$filterParams = '';
			if ($priceFilter !== false && $priceFilter['below'] != -1) {
				$filterParams = "?price={$priceFilter['above']}-{$priceFilter['below']}";
			}

			//Check if brand name was included in query and apply filter if true
			if (!empty($brandFilter)) {
				$filterParams .= (empty($filterParams) ? "?" : "&") . "manufacturer=" . $brandFilter;
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

	/**
	 * @param string[] $queryText
	 * @return string
	 */
	private function isBrandName(array $queryText): string
	{
		$inClause = implode(",", array_fill(0, count($queryText), '?'));

		return $this->connection->fetchOne(
			"SELECT eaov.value FROM eav_attribute_option_value eaov
				INNER JOIN eav_attribute_option eao ON eao.option_id = eaov.option_id
				INNER JOIN eav_attribute ea ON ea.attribute_id = eao.attribute_id
				WHERE ea.attribute_code = 'manufacturer' AND eaov.value IN ({$inClause}) LIMIT 1",
				$queryText
		);
	}
}
