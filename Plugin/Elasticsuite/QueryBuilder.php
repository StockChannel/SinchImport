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
	private Data $helper;
	private QueryFactory $queryFactory;
    private SearchProcessing $spHelper;
    private RequestInterface $request;
    private UrlInterface $urlBuilder;

	public function __construct(
		CategoryRepositoryInterface $categoryRepository,
        HttpResponse $response,
        ResourceConnection $resourceConnection,
        Data $helper,
        QueryFactory $queryFactory,
        SearchProcessing $spHelper,
        RequestInterface $request,
        UrlInterface $urlBuilder
	){
		$this->categoryRepository = $categoryRepository;
		$this->response = $response;
		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->helper = $helper;
		$this->queryFactory = $queryFactory;
		$this->spHelper = $spHelper;
        $this->request = $request;
        $this->urlBuilder = $urlBuilder;

        $this->logger = new Logger("query_builder");
        if ($this->helper->getStoreConfig('sinchimport/enhanced_search/enable_log_to_file') == 1) {
            $this->logger->pushHandler(new StreamHandler(BP . '/var/log/search_processing.log'));
        }
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
	public function aroundCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, callable $proceed, ContainerConfigurationInterface $containerConfig, array|string $queryText, string $spellingType, float $boost = 1): QueryInterface
	{
        $skip = false;
        if (is_string($queryText)) {
            if (str_starts_with($queryText, SearchProcessing::NO_PROCESSING_TAG)) {
                $queryText = substr($queryText, strlen(SearchProcessing::NO_PROCESSING_TAG));
                $skip = true;
            }
        } else if (is_array($queryText)) {
            $replaced = [];
            foreach ($queryText as $query) {
                if (str_starts_with($query, SearchProcessing::NO_PROCESSING_TAG)) {
                    $query = substr($query, strlen(SearchProcessing::NO_PROCESSING_TAG));
                    $skip = true;
                }
                $replaced[] = $query;
            }
            $queryText = $replaced;
        }

		if (!$this->helper->enhancedSearchEnabled() || $skip) {
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

        $originalQueryText = $queryText;
		//SearchProcessing use
        $this->logger->info("Original query text: " . $queryText);
        //This call can modify query text if one or more filters match
        $queryFilters = $this->spHelper->getFiltersFromQuery($containerConfig, $queryText);
        $this->logger->info("Query text after filter extraction: " . $queryText);

		//If this isn't a product autocomplete suggestion or an AJAX call and category match is successful return early
		if ($this->checkRedirect($containerConfig, $queryText, $queryFilters, $originalQueryText)) {
			//return $this->queryFactory->create(QueryInterface::TYPE_BOOL, []);
            $this->logger->info("Would otherwise return early");
		}

        if (empty(trim($queryText)) && $this->helper->getStoreConfig('sinchimport/enhanced_search/empty_query_restore_mode') == 1) {
            $queryText = $this->spHelper->processQueryTextNonDynamic($originalQueryText);
            $this->logger->info("Empty query text replaced with: " . $queryText);
        }
		//This results in modified query text if the query matched the price regex
		$originalResult = $proceed(
			$containerConfig,
			$queryText,
			$spellingType,
			$boost
		);

        $shouldClauses = [];
        if (!empty($queryText)) {
            // Pass in a list of synonyms for the category boost
            $shouldClauses[] = $this->queryFactory->create(
                'sitcCategoryBoostQuery',
                ['queries' => $this->spHelper->getQueryTextRewrites($containerConfig, $queryText)]
            );
        }

		$minShouldMatch = 0;

		$boostQuery = $this->spHelper->getBoostQuery();
		if ($boostQuery != null) {
            $this->logger->info("Adding boost to should clauses");
		    $shouldClauses[] = $boostQuery;
        }

        // If we have any boosts to add (the should clauses) or any query filters (the additional must clauses), add them to the final result
		if (!empty($shouldClauses) || !empty($queryFilters)) {
            $must = array_merge([$originalResult], array_values($queryFilters));
            if ($queryText == "") {
                $this->logger->info("Query text empty, replacing original result entirely with just container + our filters");
                // If query text is empty just replace the must clauses with the container's plus our filters
                // (otherwise the filter and multi_match clauses it adds will fuck all results, because it's got no query)
                $must = array_merge($containerConfig->getFilters(), array_values($queryFilters));
            }

		    return $this->queryFactory->create(
                QueryInterface::TYPE_BOOL,
                [
                    'must' => $must,
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
     * @param string $originalQueryText
     * @return bool true if we're redirecting
     */
	private function checkRedirect(ContainerConfigurationInterface $containerConfig, string $queryText, array $queryFilters, string $originalQueryText): bool
	{
        if ($containerConfig->getName() === self::QUERY_TYPE_PRODUCT_AUTOCOMPLETE
            || $this->request->isAjax()
            || $this->helper->getStoreConfig('sinchimport/enhanced_search/enable_redirects') != 1
        ) {
            return false;
        }

        // To avoid redirect loops and similar weirdness
        $skip_params = ['cat', 'price', 'sinch_family', 'manufacturer', 'p'];
        foreach (array_keys($this->request->getParams()) as $param) {
            if (in_array($param, $skip_params)) {
                $this->logger->info("Found skip param '{$param}' on request, won't redirect");
                return false;
            }
        }

        $redirectsOnlyStripRegex = $this->helper->getStoreConfig('sinchimport/enhanced_search/redirects_only_strip_regex_terms') == 1;
        $redirectsAvoidCategories = $this->helper->getStoreConfig('sinchimport/enhanced_search/redirects_avoid_categories') == 1;

        $queryParams = [];
        foreach ($queryFilters as $filterType => $filter) {
            if ($redirectsOnlyStripRegex && in_array($filterType, SearchProcessing::DYNAMIC_REGEX_TYPES)) {
                // This is a dynamic filter type and redirects_only_strip_regex_terms is enabled, ignore it
                continue;
            }
            if ($filter instanceof CategoryBoostFilter) {
                $cats = $filter->getCategories();
                if ($filter->getMinShouldMatch() != 1) {
                    // Ignore CategoryBoostFilters not in filter mode
                    $this->logger->warning("Ignoring CategoryBoostFilter in checkRedirect as it's not in filter mode");
                    continue;
                }
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

        if ($redirectsOnlyStripRegex) {
            $newQueryText = $this->spHelper->processQueryTextNonDynamic($originalQueryText);
            $this->logger->info("CheckRedirect: Resetting query text from '{$queryText}' to '{$newQueryText}' as redirects only strip regex is enabled");
            $queryText = $newQueryText;
        }

        if (!empty($queryParams['cat']) && empty($queryText)) {
            // This would have been set explicitly by a regex match, or by category dynamic,
            // so if query text is empty, that should mean that everything else they put was picked off by other filters
            $catId = $queryParams['cat'];
        } else if (!empty(trim($queryText))) {
            //Check whether the query text directly matches a category name & redirect if true
            $catId = $this->spHelper->getCategoryIdByName($containerConfig, trim($queryText), true);
        }

        if ($redirectsAvoidCategories && empty(trim($queryText))) {
            // No query text, but we should be avoiding categories, so reset query text
            $queryText = $this->spHelper->processQueryTextNonDynamic($originalQueryText);
            $this->logger->info("CheckRedirect: Redirects avoid categories is enabled, and query text is empty, resetting to: " . $queryText);
        } else if (!$redirectsAvoidCategories && !empty($catId)) {
            // Query text isn't necessarily empty here, but if it isn't, it was a direct category name match, so its irrelevant
            $this->logger->info("CheckRedirect: Not avoiding categories, have category target, and no relevant query text remaining, performing redirect");
            if (isset($queryParams['cat'])) unset($queryParams['cat']);
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
            if ($redirectsAvoidCategories || $redirectsOnlyStripRegex) {
                $this->logger->warning("CheckRedirect: Don't think this should ever happen, so expect some weird shit to go down now 🤷");
                $this->logger->warning("CheckRedirect: Like the fact we're going to try to redirect to a category now where we probably shouldn't");
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
            //Query text is empty, but we have no target category, try to find one based on any AttributeValueFilters present
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
