<?php

namespace SITC\Sinchimport\Helper;

use Monolog\Handler\ChromePHPHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Magento\Catalog\Model\Category;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Search\Model\Autocomplete\ItemFactory;
use Magento\Search\Model\Autocomplete\ItemInterface;
use Magento\Search\Model\SearchEngine;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Plugin\Elasticsuite\Autocomplete\TermsDataProvider;
use SITC\Sinchimport\Plugin\Elasticsuite\QueryBuilder;
use SITC\Sinchimport\Search\Request\Query\AttributeValueFilter;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterfaceFactory;
use Smile\ElasticsuiteCore\Search\Request\Builder;
use Smile\ElasticsuiteCore\Search\Request\Query\FunctionScore;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteThesaurus\Model\Index;

class SearchProcessing extends AbstractHelper
{
    // If you want to hire me, Amazon, you can do it for only 50% of the combined salaries of your developers ;)
    public const NO_PROCESSING_TAG = "🔍✍️⛔";

    public const FILTER_TYPE_PRICE = "price";
    public const FILTER_TYPE_CATEGORY = "category";
    public const FILTER_TYPE_ATTRIBUTE = "attribute";
    public const FILTER_TYPE_CATEGORY_DYNAMIC = "category_dynamic";

    private const QUERY_TEXT_BANNED_CHARS = ['?', '+', '(', ')', '\"', '*', '[', ']'];

    // All known textual filters to look for. All types are expected to return a named capture "query" containing the remaining query text, if any
    private const QUERY_REGEXES = [
        self::FILTER_TYPE_PRICE => "/(?(DEFINE)(?<price>[0-9]+(?:.[0-9]+)?)(?<cur>(?:\p{Sc}|[A-Z]{3})\s?))(?<query>.+?)\s+(?J:(?:below|under|(?:cheaper|less)\sthan)\s+(?&cur)?(?<below>(?&price))|(?:between|from)?\s*(?&cur)?(?<above>(?&price))\s*(?:and|to|-)\s*(?&cur)?(?<below>(?&price)))/u",
        self::FILTER_TYPE_CATEGORY => "/(?<query>.+)\s+in\s+(?<category>.+)/u",
        self::FILTER_TYPE_ATTRIBUTE => "", // Derived at runtime based on the available attribute values
        self::FILTER_TYPE_CATEGORY_DYNAMIC => "" // Derived at runtime based on category name and sinch_virtual_category values
    ];

    public const DYNAMIC_REGEX_TYPES = [
        self::FILTER_TYPE_ATTRIBUTE,
        self::FILTER_TYPE_CATEGORY_DYNAMIC,
    ];

    //For this to work properly with checkAttributeValueMatch, all the attributes are expected to be of type select
    public const FILTERABLE_ATTRIBUTES = [
        'sinch_family',
        'manufacturer',
    ];
    public const PARENT_ATTRIBUTES = [
        'sinch_family' => 'manufacturer',
        'sinch_family_series' => 'sinch_family'
    ];
    public const CHILD_ATTRIBUTES = [
        'manufacturer' => 'sinch_family',
        'sinch_family' => 'sinch_family_series'
    ];
    //Only match attribute values at least this long (changed to 2 to match on "HP")
    private const ATTRIBUTE_VALUE_MIN_LENGTH = 2;

    private Logger $logger;

    private string $categoryTableVarchar;
    private string $categoryTable;
    private string $eavTable;
    private string $sinchCategoriesTable;
    private string $sinchCategoriesMappingTable;

    public function __construct(
        Context                                                 $context,
        private readonly Session                                $customerSession,
        private readonly QueryFactory                           $queryFactory,
        private readonly Index                                  $thesaurus,
        private readonly ResourceConnection                     $resourceConn,
        private readonly Builder                                $requestBuilder,
        private readonly SearchEngine                           $searchEngine,
        private readonly ContainerConfigurationInterfaceFactory $containerConfigFactory,
        private readonly StoreManagerInterface                  $storeManager,
        private readonly ItemFactory                            $itemFactory,
        private readonly Data                                   $helper,
        private readonly \Smile\ElasticsuiteCore\Search\Request\Query\Builder $queryBuilder,
    ){
        parent::__construct($context);
        $this->categoryTableVarchar = $this->resourceConn->getTableName('catalog_category_entity_varchar');
        $this->categoryTable = $this->resourceConn->getTableName('catalog_category_entity');
        $this->eavTable = $this->resourceConn->getTableName('eav_attribute');
        $this->sinchCategoriesTable = $this->resourceConn->getTableName('sinch_categories');
        $this->sinchCategoriesMappingTable = $this->resourceConn->getTableName('sinch_categories_mapping');

        $this->logger = new Logger("search_processing");
        if ($this->helper->getStoreConfig('sinchimport/enhanced_search/enable_log_to_file') == 1) {
            $this->logger->pushHandler(new StreamHandler(BP . '/var/log/search_processing.log'));
        }
        $this->logger->pushHandler(new FirePHPHandler());
        $this->logger->pushHandler(new ChromePHPHandler());
        if ($this->helper->getStoreConfig('sinchimport/general/debug') != 1) {
            $this->logger->pushHandler(new NullHandler());
        }
    }

    public function getQueryTextRewrites(ContainerConfigurationInterface $containerConfig, string $queryText): array
    {
        if (str_ends_with($queryText, 's')) {
            $pluralQueryText = substr($queryText, 0, -1);
        } else {
            $pluralQueryText = $queryText . 's';
        }
        //Process thesaurus rewrites for our query
        $variants = array_values(array_unique(array_merge(
            [$queryText, $pluralQueryText],
            array_keys($this->thesaurus->getQueryRewrites($containerConfig, $queryText)),
            array_keys($this->thesaurus->getQueryRewrites($containerConfig, $pluralQueryText))
        )));
        //Pluralise each of the synonyms in thesaurus to prevent having to add plural versions to dictionary
        foreach ($variants as $queryVariant) {
            $variants[] = $queryVariant . 's';
        }
        return $variants;
    }

    /**
     * @param ContainerConfigurationInterface $containerConfig
     * @param string &$queryText Query text. Modified if filters match
     * @return QueryInterface[] Filters, indexed by FILTER_TYPE_
     */
    public function getFiltersFromQuery(ContainerConfigurationInterface $containerConfig, string &$queryText): array
    {
        $originalQueryText = $queryText;
        $filters = [];
        foreach (self::QUERY_REGEXES as $filterType => $_regex) {
            if ($this->helper->getStoreConfig('sinchimport/enhanced_search/enable_' . $filterType) != 1) {
                // Skip types not currently enabled
                continue;
            }
            $oldFilters = $filters;
            $match = $this->getRegexMatchesRaw($filterType, $queryText);
            if (!empty($match)) {
                $this->logger->info("Query text is: '{$queryText}' and filter type is {$filterType}");
                switch ($filterType) {
                    case self::FILTER_TYPE_PRICE:
                        $filter = $this->parsePriceRegex($match);
                        if (empty($filter) || empty($match['query'])) {
                            continue 2;
                        }
                        $filters[self::FILTER_TYPE_PRICE] = $filter;
                        break;
                    case self::FILTER_TYPE_CATEGORY:
//                        if (empty($match['query'])) {
//                            //There isn't a query left, so don't add a filter or change the query text
//                            // (This case should be handled by the direct category name match logic instead)
//                            $this->logger->info("No query left after category filter");
//                            continue 2;
//                        }
                        //Add category filter to $filters
                        $filters[self::FILTER_TYPE_CATEGORY] = $this->queryFactory->create(
                            'sitcCategoryBoostQuery',
                            [
                                'queries' => $this->getQueryTextRewrites($containerConfig, $match['category']),
                                'filter' => true //This function always returns filter queries (those with minShouldMatch=1)
                            ]
                        );
                        break;
                    case self::FILTER_TYPE_ATTRIBUTE:
                        $matchedFilters = $this->checkAttributeValueMatch($match['query']); //In FILTER_TYPE_ATTRIBUTE $match['query'] is the original queryText for our runtime matching
                        if (empty($matchedFilters) /*|| empty($match['query'])*/) {
                            //If we didn't get a filter, or the query text is empty, don't filter
                            $this->logger->info("Filter is empty in attribute match");
                            continue 2;
                        }
                        foreach ($matchedFilters as $filter) {
                            if (!$filter instanceof AttributeValueFilter) {
                                $this->logger->error("Got unexpected filter type from checkAttributeValueMatch");
                                continue;
                            }
                            $filters[self::FILTER_TYPE_ATTRIBUTE  . '_' . $filter->getAttribute()] = $filter;
                        }
                        break;
                    case self::FILTER_TYPE_CATEGORY_DYNAMIC:
                        if (!empty($filters[self::FILTER_TYPE_CATEGORY])) {
                            $this->logger->info("Skipping dynamic category filter as static category filter is set");
                            continue 2;
                        }
                        // Check category matches dynamically
                        $filter = $this->checkCategoryNameMatchDynamic($match['query']);
                        if (empty($filter)) {
                            $this->logger->info("Filter is empty in dynamic category match");
                            continue 2;
                        }
                        $filters[self::FILTER_TYPE_CATEGORY_DYNAMIC] = $filter;
                        break;
                }
                if ($this->testFiltersValid($match['query'] ?? '', $filters, $originalQueryText)) {
                     $this->logger->info("Filters being considered valid as they returned results");
                     //Adjust query text for the remaining checks (we only do this if the filter reports success by not doing "continue 2")
                     $queryText = $match['query'] ?? '';
                } else {
                    $this->logger->info("Most recent filter considered invalid as it returns no results, reverting its application");
                    $filters = $oldFilters;
                }
            }
        }
        $this->logger->info("Final query text is: " . $queryText);
        $this->logger->info("Returning filters of type: " . implode(", ", array_keys($filters)));
        return $filters;
    }

    // Processes queryText similarly to getFiltersFromQuery, however only processing the regex based ones, and always returning a non-empty string where possible
    // It makes no attempt to validate the results
    public function processQueryTextNonDynamic(string $queryText): string
    {
        $running = [$queryText];
        foreach (self::QUERY_REGEXES as $filterType => $regex) {
            if ($this->helper->getStoreConfig('sinchimport/enhanced_search/enable_' . $filterType) != 1) {
                // Skip types not currently enabled
                continue;
            }
            // Skip dynamic types
            if (empty($regex) || in_array($filterType, self::DYNAMIC_REGEX_TYPES)) continue;
            $match = $this->getRegexMatchesRaw($filterType, $running[array_key_last($running)]);
            if (!empty($match) && !empty($match['query'])) {
                $running[] = $match['query'];
            }
        }
        // Return the "shortest" (most processed) non-empty queryText
        foreach (array_reverse($running) as $candidate) {
            if (!empty($candidate)) {
                return $candidate;
            }
        }
        return $queryText;
    }

    private function testFiltersValid(string $queryText, array $filters, string $originalQueryText): bool
    {
        $minProds = (int)$this->helper->getStoreConfig('sinchimport/enhanced_search/filter_validate_min_prods') ?? 1;
        if ($minProds < 1) {
            $this->logger->info("Skipping filter validation as filter_validate_min_prods is {$minProds}");
            return true;
        }
        $emptyQueryRestore = $this->helper->getStoreConfig('sinchimport/enhanced_search/empty_query_restore_mode') == 1;
        //Get an instance of the quick_search_container (so we can ask it for the aggregations it would add in that view)
        /** @var ContainerConfigurationInterface $containerConfig */
        $containerConfig = $this->containerConfigFactory->create(['storeId' => $this->getCurrentStoreId(), 'containerName' => QueryBuilder::QUERY_TYPE_QUICKSEARCH]);
        // If empty query restore is on and query text is empty, perform the reset before the decision regarding the filter query
        if (empty(trim($queryText)) && $emptyQueryRestore) {
            $queryText = $this->processQueryTextNonDynamic($originalQueryText);
            $this->logger->info("TestFiltersValid: Empty query text replaced with: " . $queryText);
        }


        // Whatever you do don't call $containerConfig->getAggregations, $this->queryBuilder->createFulltextQuery (at all), or
        // $this->requestBuilder->create with a plain query text without the NO_PROCESSING_TAG or this will cause
        // infinite recursion

        if (empty(trim($queryText))) {
            $this->logger->info("TestFiltersValid: Creating filter query as query text is empty");
            $query = $this->queryBuilder->createFilterQuery($containerConfig, $filters);
            $searchRequest = $this->requestBuilder->create(
                $this->getCurrentStoreId(),
                QueryBuilder::QUERY_TYPE_QUICKSEARCH,
                0,
                0,
                $query,
                [], // sort
                [], // filter
                $containerConfig->getFilters(), // built filter
                $containerConfig->getAggregations($query), // facets
                true
            );
        } else {
            // Use a known string in the search query so we can identify our filter tests both sides of the adapter
            $searchRequest = $this->requestBuilder->create(
                $this->getCurrentStoreId(),
                QueryBuilder::QUERY_TYPE_QUICKSEARCH,
                0,
                0,
                self::NO_PROCESSING_TAG . $queryText,
                [], // sort
                [], // filter
                array_merge($containerConfig->getFilters(), array_values($filters)), // built filter
                $containerConfig->getAggregations(self::NO_PROCESSING_TAG . $queryText), // facets
                true
            );
        }

        $searchResult = $this->searchEngine->search($searchRequest);
        $numFilters = count($filters);
        $this->logger->info("Query '{$queryText}' returns {$searchResult->count()} results with its {$numFilters} filters");
        return $searchResult->count() >= $minProds;
    }

    /**
     * @param string $filterType
     * @param string $queryText
     * @return array|bool
     */
    public function getRegexMatchesRaw(string $filterType, string $queryText): array|bool
    {
        $matches = [];
        // Any type in DYNAMIC_REGEX_TYPES should return a dummy match, so it can be processed dynamically
        if (in_array($filterType, self::DYNAMIC_REGEX_TYPES)) {
            // Return a dummy "match" with the original queryText
            return ['query' => $queryText];
        }
        if (preg_match(self::QUERY_REGEXES[$filterType], $queryText, $matches) >= 1) {
            $this->logger->info("Regex match for filter type {$filterType}: " . print_r($matches, true));
            return $matches;
        }
        return false;
    }

    private function parsePriceRegex($match): ?QueryInterface
    {
        $bounds = [];
        if (!empty($match['above']) && is_numeric($match['above'])) {
            $bounds['gte'] = $match['above'];
        }
        if (!empty($match['below']) && is_numeric($match['below']) && $match['below'] != -1) {
            $bounds['lte'] = $match['below'];
        }
        if(empty($bounds)) {
            return null;
        }

        $groupId = Group::NOT_LOGGED_IN_ID;
        try {
            $groupId = $this->customerSession->getCustomerGroupId();
        } catch (NoSuchEntityException | LocalizedException) {}

        return $this->queryFactory->create(
            'sitcPriceRangeQuery',
            [
                'bounds' => $bounds,
                'account_group' => $groupId
            ]
        );
    }

    /**
     * Check if the given attribute has a value matching queryText,
     * returning an array of the matches, ordered by length (desc)
     * @param string $attributeCode
     * @param string $queryText
     * @param string[]|null $validIds
     * @return string[]
     */
    public function queryTextContainsAttributeValue(string $attributeCode, string $queryText, ?array $validIds = null): array
    {
        $eav_attribute = $this->resourceConn->getTableName('eav_attribute');
        $eav_attribute_option = $this->resourceConn->getTableName('eav_attribute_option');
        $eav_attribute_option_value = $this->resourceConn->getTableName('eav_attribute_option_value');
        if (!is_null($validIds) && count($validIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            // Min length is 1 on sinch_family_series when being selected as a child (allow "ThinkPad X 1", where X is a series)
            $params = array_merge([$attributeCode, $attributeCode == 'sinch_family_series' ? 1 : self::ATTRIBUTE_VALUE_MIN_LENGTH, $queryText], $validIds);
            return $this->resourceConn->getConnection()->fetchCol(
                "SELECT eaov.value FROM $eav_attribute_option_value eaov
                        INNER JOIN $eav_attribute_option eao
                            ON eaov.option_id = eao.option_id
                        INNER JOIN $eav_attribute ea
                            ON eao.attribute_id = ea.attribute_id
                        WHERE ea.attribute_code = ?
                            AND CHAR_LENGTH(eaov.value) >= ?
                            AND {$this->getBannedQueryTextSql()}
                            AND ? REGEXP CONCAT('\\\\b', eaov.value, '\\\\b')
                            AND eao.option_id IN ($placeholders)
                        ORDER BY CHAR_LENGTH(eaov.value) DESC",
                $params
            );
        }
        return $this->resourceConn->getConnection()->fetchCol(
            "SELECT eaov.value FROM $eav_attribute_option_value eaov
                        INNER JOIN $eav_attribute_option eao
                            ON eaov.option_id = eao.option_id
                        INNER JOIN $eav_attribute ea
                            ON eao.attribute_id = ea.attribute_id
                        WHERE ea.attribute_code = :attribute
                            AND CHAR_LENGTH(eaov.value) >= :valMinLength
                            AND {$this->getBannedQueryTextSql()}
                            AND :queryText REGEXP CONCAT('\\\\b', eaov.value, '\\\\b')
                        ORDER BY CHAR_LENGTH(eaov.value) DESC",
            [
                ':attribute' => $attributeCode,
                ':valMinLength' => self::ATTRIBUTE_VALUE_MIN_LENGTH,
                ':queryText' => $queryText
            ]
        );
    }

    /**
     * Parses the queryText for attribute value matches
     * @param string $queryText
     * @return QueryInterface[]|null
     */
    private function checkAttributeValueMatch(string &$queryText): ?array
    {
        if (empty($queryText)) {
            return null;
        }

        $results = [];
        foreach (self::FILTERABLE_ATTRIBUTES as $attribute) {
            //Match on values for this attribute, preferring to match longer values if possible
            $matchingValues = $this->queryTextContainsAttributeValue($attribute, $queryText);
            if (!empty($matchingValues)) {
                $this->logger->info("$attribute matching values: " . implode(", ", $matchingValues));
                //For now, we only take the first matching value, then strip it from the queryText and return a QueryInterface representing the filter
                $queryText = $this->stripFromQueryText($queryText, $matchingValues[0]);
                $results[] = $this->queryFactory->create(
                    'sitcAttributeValueQuery',
                    [
                        'attribute' => $attribute,
                        'value' => $matchingValues[0]
                    ]
                );
                // Now apply parent and child value matches as necessary
                $additional = $this->checkApplyParentAndChildValuesMatch($queryText, $attribute, $matchingValues[0]);
                if (!empty($additional)) {
                    $results = array_merge($results, $additional);
                }
            }
        }
        // Unnecessary, but just to complete the convention of only returning arrays with data
        if (empty($results)) {
            return null;
        }
        return $results;
    }

    private function checkApplyParentAndChildValuesMatch(string &$queryText, string $attribute, string $matchingValue): ?array
    {
        $results = [];
        $mainBackingTable = $this->getSinchTable($attribute);
        $mainIdCol = $this->getIdColumn($attribute);
        $mainValueCol = $this->getValueColumn($attribute);
        $conn = $this->resourceConn->getConnection();

        // Handle parent
        if (!empty(self::PARENT_ATTRIBUTES[$attribute])) {
            $parentAttribute = self::PARENT_ATTRIBUTES[$attribute];
            $parentTable = $this->getSinchTable($parentAttribute);
            $valueCol = $this->getValueColumn($parentAttribute);
            $parentIdCol = $this->getIdColumn($parentAttribute);
            $refCol = $this->getParentReferenceColumn($attribute);
            // Retrieve associated parent value
            $parentValue = $conn->fetchOne(
                "SELECT parent.{$valueCol}
                    FROM {$mainBackingTable} main
                    INNER JOIN {$parentTable} parent
                        ON main.{$refCol} = parent.{$parentIdCol}
                    WHERE main.{$mainValueCol} = :primaryValue",
                [":primaryValue" => $matchingValue]
            );
            $this->logger->info("Applying parent ($parentAttribute) value match: $parentValue (from $matchingValue)");
            // Strip it from the query text if present
            $queryText = $this->stripFromQueryText($queryText, $parentValue);
            // Append an attribute value filter matching its value
            $results[] = $this->queryFactory->create(
                'sitcAttributeValueQuery',
                [
                    'attribute' => $parentAttribute,
                    'value' => $parentValue
                ]
            );
        }

        // Handle children
        if (!empty(self::CHILD_ATTRIBUTES[$attribute])) {
            $childAttribute = self::CHILD_ATTRIBUTES[$attribute];
            $childTable = $this->getSinchTable($childAttribute);
            $refCol = $this->getParentReferenceColumn($childAttribute);
            $childOptionIds = $conn->fetchCol(
                "SELECT child.shop_option_id
                    FROM {$childTable} child
                    WHERE child.{$refCol} IN (
                        SELECT main.{$mainIdCol}
                            FROM {$mainBackingTable} main
                            WHERE main.{$mainValueCol} = :primaryValue
                    )
                    AND child.shop_option_id IS NOT NULL",
                [":primaryValue" => $matchingValue]
            );
            $matchingValues = $this->queryTextContainsAttributeValue($childAttribute, $queryText, $childOptionIds);
            if (!empty($matchingValues)) {
                $this->logger->info("$childAttribute matching values (child of $attribute): " . implode(", ", $matchingValues));
                //For now, we only take the first matching value, then strip it from the queryText and return a QueryInterface representing the filter
                $queryText = $this->stripFromQueryText($queryText, $matchingValues[0]);
                $results[] = $this->queryFactory->create(
                    'sitcAttributeValueQuery',
                    [
                        'attribute' => $childAttribute,
                        'value' => $matchingValues[0]
                    ]
                );
            }
        }

        if (!empty($results)) {
            return $results;
        }
        return null;
    }

    private function getSinchTable(string $attribute): string
    {
        return match ($attribute) {
            'manufacturer' => 'sinch_manufacturers',
            'sinch_family' => 'sinch_family',
            'sinch_family_series' => 'sinch_family_series',
        };
    }

    private function getIdColumn(string $attribute): string
    {
        return match ($attribute) {
            'manufacturer' => 'sinch_manufacturer_id',
            'sinch_family', 'sinch_family_series' => 'id',
        };
    }

    private function getValueColumn(string $attribute): string
    {
        return match ($attribute) {
            'manufacturer' => 'manufacturer_name',
            'sinch_family', 'sinch_family_series' => 'name',
        };
    }

    private function getParentReferenceColumn(string $attribute): string
    {
        return match ($attribute) {
            'sinch_family' => 'brand_id',
            'sinch_family_series' => 'family_id',
        };
    }

    /**
     * Strip $strip from $queryText (case-insensitively), returning the modified queryText
     * @param string $queryText
     * @param string $strip
     * @return string
     */
    public function stripFromQueryText(string $queryText, string $strip): string
    {
        // The original version of this function just did str_ireplace, but it didn't respect word boundaries
        // We still do str_replace to dedup spaces in the middle, in lieu of a better solution
        return trim(str_replace('  ', ' ', preg_replace('/\b' . preg_quote($strip, '/') . '\b/i', '', $queryText)));
    }

    /**
     * @param string $queryText
     * @return ItemInterface[]
     */
    public function getAutocompleteSuggestions(string $queryText): array
    {
        $suggestions = [];
        if ($this->helper->getStoreConfig('sinchimport/enhanced_search/enable_category') != 1
            || $this->helper->getStoreConfig('sinchimport/enhanced_search/enable_autocomplete_suggestions') != 1) {
            // These autocomplete suggestions rely entirely on the functionality of our non-dynamic category filter,
            // so if it isn't enabled, just return now
            return $suggestions;
        }

        //Get an instance of the quick_search_container (so we can ask it for the aggregations it would add in that view)
        /** @var ContainerConfigurationInterface $containerConfig */
        $containerConfig = $this->containerConfigFactory->create(['storeId' => $this->getCurrentStoreId(), 'containerName' => QueryBuilder::QUERY_TYPE_QUICKSEARCH]);
        $aggParams = $containerConfig->getAggregations($queryText);

        $searchRequest = $this->requestBuilder->create(
            $this->getCurrentStoreId(),
            QueryBuilder::QUERY_TYPE_PRODUCT_AUTOCOMPLETE,
            0,
            0,
            $queryText,
            [],
            [],
            $containerConfig->getFilters(),
            ['categories' => $aggParams['categories']]
        );

        $searchResult = $this->searchEngine->search($searchRequest);
        $categoryBucket = $searchResult->getAggregations()->getBucket('categories');
        if (!empty($categoryBucket)) {
            $catToCountMapping = [];
            foreach ($categoryBucket->getValues() as $value) {
                $valueCount = $value->getMetrics()['count'];
                if ($valueCount == 0) continue;
                $catToCountMapping[$value->getValue()] = $valueCount;
            }

            if (empty($catToCountMapping)) {
                return $suggestions;
            }

            //Filter the aggregates to what we consider to be meaningful (i.e. exclude things like "Uncategorised Products")
            $inClause = implode(",", array_fill(0, count($catToCountMapping), '?'));
            //The left join on the subquery combined with the IFNULL allows us to prioritize leaf categories without excluding parents
            // as the subquery only determines direct child counts, and thus parents (without products) are assumed to have the max viable (50k) product count
            $validCatSuggestions = $this->resourceConn->getConnection()->fetchPairs(
                "SELECT scm.shop_entity_id, IFNULL(cpc.product_count, 50000) FROM sinch_categories_mapping scm
                    INNER JOIN sinch_categories sc
                        ON scm.store_category_id = sc.store_category_id
                    -- TODO: Better determine how to read product counts
                    LEFT JOIN (SELECT category_id, COUNT(product_id) as product_count FROM catalog_category_product GROUP BY category_id) cpc
                        ON scm.shop_entity_id = cpc.category_id
                    WHERE sc.include_in_menu = 1
                        AND sc.products_within_this_category + sc.products_within_sub_categories > 1
                        AND sc.products_within_this_category + sc.products_within_sub_categories < 50000
                        AND scm.shop_entity_id IN ($inClause)",
                array_keys($catToCountMapping)
            );
            //Our adjusted map only holds results we've deemed viable, and values as a percentage coverage of the category
            $adjustedCatMap = [];
            foreach ($catToCountMapping as $cat => $count) {
                if (isset($validCatSuggestions[$cat])) {
                    $adjustedCatMap[$cat] = ($count * 100) / $validCatSuggestions[$cat];
                }
            }

            arsort($adjustedCatMap, SORT_NUMERIC);
            foreach ($adjustedCatMap as $cat => $coverage) {
                $this->logger->info("Category {$cat} has {$coverage}% product coverage for query");

                //Add a suggestion for the aggregate
                $suggestions[] = $this->itemFactory->create([
                    'title' => $this->formatCategoryTerm($queryText, $cat),
                    'type' => TermsDataProvider::AUTOCOMPLETE_TYPE,
                ]);
            }
        }

        return $suggestions;
    }

    private function getCurrentStoreId(): int
    {
        $storeId = 0;
        try {
            $storeId = $this->storeManager->getStore()->getId();
        } catch (NoSuchEntityException $e) {}
        if ($storeId == 0) {
            $storeId = $this->storeManager->getDefaultStoreView()->getId();
        }
        return $storeId;
    }

    private function formatCategoryTerm(string $queryText, int $categoryId): string
    {
        return $queryText . " in " . $this->getCategoryName($categoryId);
    }

    private function getCategoryName(int $categoryId): string
    {
        $eav_entity_type = $this->resourceConn->getTableName('eav_entity_type');
        return $this->resourceConn->getConnection()->fetchOne(
            "SELECT ccev.value FROM {$this->categoryTableVarchar} ccev
                INNER JOIN {$this->eavTable} ea
                    ON ccev.attribute_id = ea.attribute_id
                INNER JOIN {$eav_entity_type} eet
                    ON ea.entity_type_id = eet.entity_type_id
                WHERE ccev.entity_id = :categoryId
                  AND ea.attribute_code = 'name'
                  AND eet.entity_type_code = :catEntityType
                  AND ccev.store_id = :storeId",
            [
                ':catEntityType' => Category::ENTITY,
                ':categoryId' => $categoryId,
                ':storeId' => $this->getCurrentStoreId()
            ]
        );
    }

    /**
     * @param ContainerConfigurationInterface $containerConfig
     * @param string|string[] $categoryName
     * @param bool $processVariants
     * @return int|null
     */
    public function getCategoryIdByName(ContainerConfigurationInterface $containerConfig, array|string $categoryName, bool $processVariants = true): ?int
    {
        if (!is_array($categoryName)) {
            $categoryName = [$categoryName];
        }

        $inClause = implode(",", array_fill(0, count($categoryName), '?'));
        //Arrays merged in bind to ensure the number of params matches number of tokens
        // TODO: Needs some kind of store or category path filter to exclude results from other trees
        $categoryId = $this->resourceConn->getConnection()->fetchOne(
            "SELECT ccev.entity_id FROM {$this->categoryTableVarchar} ccev 
                JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
                JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id
				JOIN {$this->sinchCategoriesMappingTable} scm ON scm.shop_entity_id = cce.entity_id
				JOIN {$this->sinchCategoriesTable} sc ON sc.store_category_id = scm.store_category_id AND sc.products_within_this_category < 100000
                WHERE ccev.value IN ($inClause) OR sc.VirtualCategory IN ($inClause)",
            array_merge($categoryName, $categoryName)
        );

        if (!empty($categoryId)) {
            return $categoryId;
        }
        if ($processVariants) {
            $rewrites = $this->getQueryTextRewrites($containerConfig, $categoryName[0]);
            if (!empty($rewrites)) {
                return $this->getCategoryIdByName($containerConfig, $rewrites, false);
            }
        }
        return null;
    }

    /**
     * @return QueryInterface|null
     */
    public function getBoostQuery(): ?QueryInterface
    {
        if ($this->helper->popularityBoostEnabled()) {
            return $this->queryFactory->create(
                QueryInterface::TYPE_FUNCTIONSCORE,
                [
                    'query' => $this->queryFactory->create(QueryInterface::TYPE_FILTER), //Filtered with no args is a match_all
                    'functions' => [
                        [ //Boost on Popularity Score
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_score',
                                'factor' => $this->helper->scoreBoostFactor(),
                                'modifier' => $this->helper->scoreBoostModifier(),
                                'missing' => 0
                            ],
                            'weight' => $this->helper->scoreBoostWeight()
                        ],
                        [ //Boost on Monthly BI data
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_popularity_month',
                                'factor' => $this->helper->monthlyPopularityBoostFactor(),
                                'modifier' => $this->helper->monthlyPopularityBoostModifier(),
                                'missing' => 0
                            ],
                            'weight' => $this->helper->monthlyPopularityBoostWeight()
                        ],
                        [ //Boost on Yearly BI data
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_popularity_year',
                                'factor' => $this->helper->yearlyPopularityBoostFactor(),
                                'modifier' => $this->helper->yearlyPopularityBoostModifier(),
                                'missing' => 0
                            ],
                            'weight' => $this->helper->yearlyPopularityBoostWeight()
                        ],
                        [ //Boost on sinch search data
                            FunctionScore::FUNCTION_SCORE_FIELD_VALUE_FACTOR => [
                                'field' => 'sinch_searches',
                                'factor' => $this->helper->searchesBoostFactor(),
                                'modifier' => $this->helper->searchesBoostModifier(),
                                'missing' => 0
                            ],
                            'weight' => $this->helper->searchesBoostWeight()
                        ]
                    ],
                    'scoreMode' => $this->helper->popularityScoringMode(),
                    'boostMode' => $this->helper->popularityBoostMode(),
                ]
            );
        }
        return null;
    }


    public function checkCategoryNameMatchDynamic(string &$queryText): ?QueryInterface
    {
        $catalog_category_entity_varchar = $this->resourceConn->getTableName('catalog_category_entity_varchar');
        $eav_attribute = $this->resourceConn->getTableName('eav_attribute');
        $eav_entity_type = $this->resourceConn->getTableName('eav_entity_type');
        $catalog_category_entity_int = $this->resourceConn->getTableName('catalog_category_entity_int');
        $eav_attribute_option = $this->resourceConn->getTableName('eav_attribute_option');
        $eav_attribute_option_value = $this->resourceConn->getTableName('eav_attribute_option_value');

        $this->logger->info("Checking dynamic category name match");
        $matchingName = $this->resourceConn->getConnection()->fetchOne(
            "SELECT ccev.value FROM $catalog_category_entity_varchar ccev
                INNER JOIN $eav_attribute ea
                    ON ccev.attribute_id = ea.attribute_id
                INNER JOIN $eav_entity_type eet
                    ON ea.entity_type_id = eet.entity_type_id
                WHERE ea.attribute_code = 'name'
                  AND eet.entity_type_code = :entityTypeCode
                  AND ccev.store_id = :storeId
                  AND CHAR_LENGTH(ccev.value) >= :valMinLength
                  AND {$this->getBannedQueryTextSql('ccev.value')}
                  AND :queryText REGEXP CONCAT('\\\\b', ccev.value, '\\\\b')
                ORDER BY CHAR_LENGTH(ccev.value) DESC
                LIMIT 1",
            [
                ":entityTypeCode" => Category::ENTITY,
                ":storeId" => $this->getCurrentStoreId(),
                ":valMinLength" => self::ATTRIBUTE_VALUE_MIN_LENGTH,
                ":queryText" => $queryText
            ]
        );
        if (!empty($matchingName)) {
            $this->logger->info("Found dynamic category name match: {$matchingName}");
            // Name directly matched a category, so strip match from the queryText and create a category boost query
            $queryText = $this->stripFromQueryText($queryText, $matchingName);
            return $this->queryFactory->create(
                'sitcCategoryBoostQuery',
                [
                    'queries' => [$matchingName],
                    'filter' => true //This function always returns filter queries (those with minShouldMatch=1)
                ]
            );
        }

        // Now check sinch_virtual_category, seeing as none of the actual category names matched
        $this->logger->info("Checking dynamic virtual category name match");
        $matchingVirtualCat = $this->resourceConn->getConnection()->fetchOne(
            "SELECT eaov.value FROM $catalog_category_entity_int ccei
                INNER JOIN $eav_attribute_option eao
                    ON ccei.value = eao.option_id
                INNER JOIN $eav_attribute_option_value eaov
                    ON eao.option_id = eaov.option_id
                INNER JOIN $eav_attribute ea
                    ON ccei.attribute_id = ea.attribute_id
                INNER JOIN $eav_entity_type eet
                    ON ea.entity_type_id = eet.entity_type_id
                WHERE ea.attribute_code = 'sinch_virtual_category'
                  AND eet.entity_type_code = :entityTypeCode
                  AND ccei.store_id = 0 -- Virtual category values are always inserted into scope 0 by the import
                  AND CHAR_LENGTH(eaov.value) >= :valMinLength
                  AND {$this->getBannedQueryTextSql()}
                  AND :queryText REGEXP CONCAT('\\\\b', eaov.value, '\\\\b')
                ORDER BY CHAR_LENGTH(eaov.value) DESC
                LIMIT 1",
            [
                ":entityTypeCode" => Category::ENTITY,
                ":valMinLength" => self::ATTRIBUTE_VALUE_MIN_LENGTH,
                ":queryText" => $queryText
            ]
        );
        if (!empty($matchingVirtualCat)) {
            $this->logger->info("Found dynamic virtual category name match: {$matchingVirtualCat}");
            // Name directly matched a virtual category, so strip match from the queryText and create a category boost query
            $queryText = $this->stripFromQueryText($queryText, $matchingVirtualCat);
            return $this->queryFactory->create(
                'sitcCategoryBoostQuery',
                [
                    'queries' => [$matchingVirtualCat],
                    'filter' => true //This function always returns filter queries (those with minShouldMatch=1)
                ]
            );
        }
        return null;
    }

    private function getBannedQueryTextSql(string $field = 'eaov.value'): string
    {
        $sql = '';
        foreach (self::QUERY_TEXT_BANNED_CHARS as $char) {
            $sql .= !empty($sql) ? ' AND ' : '';
            $sql .= "{$field} NOT LIKE '%{$char}%'";
        }
        return $sql;
    }
}
