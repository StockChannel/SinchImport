<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Monolog\Handler\ChromePHPHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\NullHandler;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\SearchProcessing;
use SITC\Sinchimport\Search\Request\Query\AttributeValueFilter;
use SITC\Sinchimport\Search\Request\Query\CategoryBoostFilter;
use SITC\Sinchimport\Search\Request\Query\PriceRangeQuery;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class QueryBuilder
{

    const QUERY_TYPE_PRODUCT_AUTOCOMPLETE = 'catalog_product_autocomplete';
	const QUERY_TYPE_QUICKSEARCH = 'quick_search_container';

	private CategoryRepositoryInterface $categoryRepository;
	private HttpResponse $response;
	private Logger $logger;
	private ResourceConnection $resourceConnection;
	private AdapterInterface $connection;
	private string $categoryTableVarchar;
	private string $categoryTable;
	private string $eavTable;
    private string $sinchCategoriesTable;
	private string $sinchCategoriesMappingTable;
	private string $productFamilyTable;
	private string $sinchProductsTable;

	private Data $helper;
	/** @var QueryFactory $queryFactory */
	private $queryFactory;
    private SearchProcessing $spHelper;
    private RequestInterface $request;
    private UrlInterface $urlBuilder;

	public function __construct(
		CategoryRepositoryInterface $categoryRepository,
        HttpResponse $response,
        ResourceConnection $resourceConnection,
        Data $helper,
        QueryFactory\Proxy $queryFactory,
        SearchProcessing $spHelper,
        RequestInterface $request,
        UrlInterface $urlBuilder
	){
		$this->categoryRepository = $categoryRepository;
		$this->response = $response;
		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->categoryTableVarchar = $this->connection->getTableName('catalog_category_entity_varchar');
		$this->categoryTable = $this->connection->getTableName('catalog_category_entity');
		$this->eavTable = $this->connection->getTableName('eav_attribute');
		$this->sinchCategoriesTable = $this->connection->getTableName('sinch_categories');
		$this->sinchCategoriesMappingTable = $this->connection->getTableName('sinch_categories_mapping');
		$this->productFamilyTable = $this->connection->getTableName('sinch_family');
		$this->sinchProductsTable = $this->connection->getTableName('sinch_products');
		$this->helper = $helper;
		$this->queryFactory = $queryFactory;
		$this->spHelper = $spHelper;
        $this->request = $request;
        $this->urlBuilder = $urlBuilder;

        $this->logger = new Logger("query_builder");
        $this->logger->pushHandler(new StreamHandler(BP . '/var/log/search_processing.log'));
        $this->logger->pushHandler(new FirePHPHandler());
        $this->logger->pushHandler(new ChromePHPHandler());
        if ($this->helper->getStoreConfig('sinchimport/general/debug') != 1) {
            $this->logger->pushHandler(new NullHandler());
        }
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
	public function aroundCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, callable $proceed, ContainerConfigurationInterface $containerConfig, $queryText, string $spellingType, float $boost = 1): QueryInterface
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
        $this->logger->info("Original query text: " . $queryText);
        //This call can modify query text if one or more filters match
        $queryFilters = $this->spHelper->getFiltersFromQuery($containerConfig, $queryText);
        $this->logger->info("Query text after filter extraction: " . $queryText);

		//If this isn't a product autocomplete suggestion or an AJAX call and category match is successful return early
		if ($this->checkRedirect($containerConfig, $queryText, $queryFilters)) {
			return $this->queryFactory->create(QueryInterface::TYPE_BOOL, []);
		}

		//This results in modified query text if the query matched the price regex
		$originalResult = $proceed(
			$containerConfig,
			$queryText,
			$spellingType,
			$boost
		);

		//Pass in a list of synonyms for the category boost
		$shouldClauses = [
            $this->queryFactory->create(
                'sitcCategoryBoostQuery',
                [
                    'queries' => $this->spHelper->getQueryTextRewrites($containerConfig, $queryText)
                ]
            )
        ];
		$minShouldMatch = 0;

		$boostQuery = $this->spHelper->getBoostQuery();
		if ($boostQuery != null) {
            $this->logger->info("Adding boost to should clauses");
		    $shouldClauses[] = $boostQuery;
        }

        //If we have any boosts to add (the should clauses) or any query filters (the additional must clauses), add them to the final result
		if (!empty($shouldClauses) || !empty($queryFilters)) {
		    return $this->queryFactory->create(
                QueryInterface::TYPE_BOOL,
                [
                    'must' => array_merge([$originalResult], array_values($queryFilters)),
                    'should' => $shouldClauses,
                    'minimumShouldMatch' => $minShouldMatch
                ]
            );
        }

		return $originalResult;
    }


    /**
     * @param ContainerConfigurationInterface $containerConfig
     * @param string $queryText
     * @param QueryInterface[] $queryFilters
     * @return bool true if we're redirecting
     */
	private function checkRedirect(ContainerConfigurationInterface $containerConfig, string $queryText, array $queryFilters): bool
	{
        if ($containerConfig->getName() === self::QUERY_TYPE_PRODUCT_AUTOCOMPLETE || $this->request->isAjax()) {
            return false;
        }

        $queryParams = [];
        foreach ($queryFilters as $filter) {
            if ($filter instanceof CategoryBoostFilter) {
                $cats = $filter->getCategories();
                if (!empty($cats)) {
                    $catId = $this->spHelper->getCategoryIdByName($containerConfig, $cats);
                    if (!empty($catId)) {
                        $queryParams['cat'] = $catId;
                    }
                }
            } else if ($filter instanceof PriceRangeQuery) {
                $bounds = $filter->getBounds();
                if (!empty($bounds['lte']) && is_numeric($bounds['lte'])) {
                    $min = $bounds['gte'] ?? '0';
                    $queryParams['price'] = "{$min}-{$bounds['lte']}";
                }
            } else if ($filter instanceof AttributeValueFilter) {
                $attrCode = $filter->getAttribute();
                $attrValue = $filter->getValue();
                $queryParams[$attrCode] = $attrValue;
            }
        }

        //Check whether the query text directly matches a category name & redirect if true
        if (!empty(trim($queryText)) && ($catId = $this->spHelper->getCategoryIdByName($containerConfig, trim($queryText), true)) != null) {
            $catUrl = $this->getCategoryUrl($catId, $queryParams);
            $redirectUrl = $this->urlBuilder->getUrl($catUrl);
            $this->response->setRedirect($redirectUrl)->sendResponse();
            return true;
        }

        if (!empty($queryParams)) {
            if (!empty(trim($queryText))) {
                //This is still a search query, so redirect to search results with relevant filters set
                $redirectUrl = $this->urlBuilder->getUrl('catalogsearch/result/index', ['_query' => array_merge(['q' => $queryText], $queryParams)]);
                $this->response->setRedirect($redirectUrl)->sendResponse();
                return true;
            }
            //Query text is empty, so we should redirect to a category if one was specified
            if (!empty($queryParams['cat'])) {
                $catId = $queryParams['cat'];
                unset($queryParams['cat']);
                $catUrl = $this->getCategoryUrl($catId, $queryParams);
                if (!empty($catUrl)) {
                    $this->response->setRedirect($catUrl)->sendResponse();
                    return true;
                }
            }
            //Query text is empty but we have no target category, try to find one based on any AttributeValueFilters present
            $catalog_category_product = $this->connection->getTableName('catalog_category_product');
            $catalog_product_index_eav = $this->connection->getTableName('catalog_product_index_eav');
            $eav_attribute_option_value = $this->connection->getTableName('eav_attribute_option_value');
            $eav_attribute_option = $this->connection->getTableName('eav_attribute_option');
            $eav_attribute = $this->connection->getTableName('eav_attribute');
            foreach ($queryFilters as $filter) {
                if (!$filter instanceof AttributeValueFilter) continue;
                $this->logger->info("Processing attribute {$filter->getAttribute()} to determine target category");
                $catId = $this->connection->fetchOne(
                    "SELECT ccp.category_id FROM $catalog_category_product ccp
                        INNER JOIN $catalog_product_index_eav cpie
                            ON ccp.product_id = cpie.entity_id
                            AND cpie.value IN (
                                SELECT eaov.option_id
                                    FROM $eav_attribute_option_value eaov
                                    INNER JOIN $eav_attribute_option eao
                                        ON eaov.option_id = eao.option_id
                                    INNER JOIN $eav_attribute ea
                                    ON eao.attribute_id = ea.attribute_id
                                    WHERE ea.attribute_code = :attr AND eaov.value = :value
                            )
                        GROUP BY ccp.category_id
                        ORDER BY COUNT(ccp.category_id) DESC
                        LIMIT 1",
                    [':attr' => $filter->getAttribute(), ':value' => $filter->getValue()]
                );
                if (!empty($catId)) {
                    $this->logger->info("Found candidate category for attribute {$filter->getAttribute()}: {$catId}. Using it");
                    $catUrl = $this->getCategoryUrl($catId, $queryParams);
                    $this->response->setRedirect($catUrl)->sendResponse();
                    return true;
                }
            }
            //We still have no query text, but we've run out of ways to filter, so just run it through normally?
        }
		return false;
	}

    private function getCategoryUrl(int $categoryId, array $queryParams): ?string
    {
        try {
            $queryString = http_build_query($queryParams);
            $urlParams = empty($queryString) ? "" : "?{$queryString}";
            return $this->categoryRepository->get($categoryId)->getUrl() . $urlParams;
        } catch (NoSuchEntityException $e) {
            $this->logger->warning("Got no such entity retrieving category");
        }
        return null;
    }
}
