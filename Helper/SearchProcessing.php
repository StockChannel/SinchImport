<?php

namespace SITC\Sinchimport\Helper;

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
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfiguration\AggregationResolverInterface;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterfaceFactory;
use Smile\ElasticsuiteCore\Search\Request\Builder;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteThesaurus\Model\Index;

class SearchProcessing extends AbstractHelper
{
    public const FILTER_TYPE_PRICE = "price";
    public const FILTER_TYPE_CATEGORY = "category";
    public const FILTER_TYPE_ATTRIBUTE = "attribute";

    // All known textual filters to look for. All types are expected to return a named capture "query" containing the remaining query text, if any
    private const QUERY_REGEXES = [
        self::FILTER_TYPE_PRICE => "/(?(DEFINE)(?<price>[0-9]+(?:.[0-9]+)?)(?<cur>(?:\p{Sc}|[A-Z]{3})\s?))(?<query>.+?)\s+(?J:(?:below|under|(?:cheaper|less)\sthan)\s+(?&cur)?(?<below>(?&price))|(?:between|from)?\s*(?&cur)?(?<above>(?&price))\s*(?:and|to|-)\s*(?&cur)?(?<below>(?&price)))/u",
        self::FILTER_TYPE_CATEGORY => "/(?<query>.+)\s+in\s+(?<category>.+)/u",
        self::FILTER_TYPE_ATTRIBUTE => "" //This regex is derived at runtime based on the available attribute values
    ];

    //For this to work properly with checkAttributeValueMatch, all the attributes are expected to be of type select
    public const FILTERABLE_ATTRIBUTES = [
        'manufacturer',
        'sinch_family'
    ];
    //Only match attribute values at least this long
    private const ATTRIBUTE_VALUE_MIN_LENGTH = 3;

    private Session $customerSession;
    private QueryFactory $queryFactory;
    private Index $thesaurus;
    private ResourceConnection $resourceConn;
    private Builder $requestBuilder;
    private SearchEngine $searchEngine;
    private ContainerConfigurationInterfaceFactory $containerConfigFactory;
    private StoreManagerInterface $storeManager;
    private \Zend\Log\Logger $logger;
    private ItemFactory $itemFactory;

    public function __construct(
        Context $context,
        Session $customerSession,
        QueryFactory\Proxy $queryFactory,
        Index\Proxy $thesaurus,
        ResourceConnection\Proxy $resourceConn,
        Builder\Proxy $requestBuilder,
        SearchEngine\Proxy $searchEngine,
        ContainerConfigurationInterfaceFactory $containerConfigFactory,
        StoreManagerInterface\Proxy $storeManager,
        ItemFactory $itemFactory
    ){
        parent::__construct($context);
        $this->customerSession = $customerSession;
        $this->queryFactory = $queryFactory;
        $this->thesaurus = $thesaurus;
        $this->resourceConn = $resourceConn;
        $this->requestBuilder = $requestBuilder;
        $this->searchEngine = $searchEngine;
        $this->containerConfigFactory = $containerConfigFactory;
        $this->storeManager = $storeManager;
        $this->itemFactory = $itemFactory;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/search_processing.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
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
        $filters = [];
        foreach (self::QUERY_REGEXES as $filterType => $_regex) {
            $match = $this->getRegexMatchesRaw($filterType, $queryText);
            if (!empty($match)) {
                switch ($filterType) {
                    case self::FILTER_TYPE_PRICE:
                        $filter = $this->parsePriceRegex($match);
                        if (empty($filter) || empty($match['query'])) {
                            continue 2;
                        }
                        $filters[self::FILTER_TYPE_PRICE] = $filter;
                        break;
                    case self::FILTER_TYPE_CATEGORY:
                        if (empty($match['query'])) {
                            //There isn't a query left, so don't add a filter or change the query text
                            // (This case should be handled by the direct category name match logic instead)
                            continue 2;
                        }
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
                        $filter = $this->checkAttributeValueMatch($match['query']); //In FILTER_TYPE_ATTRIBUTE $match['query'] is the original queryText for our runtime matching
                        if (empty($filter) || empty($match['query'])) {
                            //If we didn't get a filter, or the query text is empty, don't filter
                            continue 2;
                        }
                        $filters[self::FILTER_TYPE_ATTRIBUTE] = $filter;
                        break;
                }
                //Adjust query text for the remaining checks (we only do this if the filter reports success by not doing "continue 2")
                $queryText = $match['query'] ?? '';
            }
        }
        return $filters;
    }

    /**
     * @param string $filterType
     * @param string $queryText
     * @return array|bool
     */
    public function getRegexMatchesRaw(string $filterType, string $queryText)
    {
        $matches = [];
        //Attribute is a special case as it requires runtime generation
        if ($filterType == self::FILTER_TYPE_ATTRIBUTE) {
            //Return a dummy "match" with the original queryText, so we can do it dynamically
            return ['query' => $queryText];
        }
        if (preg_match(self::QUERY_REGEXES[$filterType], $queryText, $matches) >= 1) {
            return $matches[0];
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
        } catch (NoSuchEntityException | LocalizedException $e) {}

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
     * @return string[]
     */
    public function queryTextContainsAttributeValue(string $attributeCode, string $queryText): array
    {
        $eav_attribute = $this->resourceConn->getTableName('eav_attribute');
        $eav_attribute_option = $this->resourceConn->getTableName('eav_attribute_option');
        $eav_attribute_option_value = $this->resourceConn->getTableName('eav_attribute_option_value');
        return $this->resourceConn->getConnection()->fetchCol(
            "SELECT eaov.value FROM $eav_attribute_option_value eaov
                        INNER JOIN $eav_attribute_option eao
                            ON eaov.option_id = eao.option_id
                        INNER JOIN $eav_attribute ea
                            ON eao.attribute_id = ea.attribute_id
                        WHERE ea.attribute_code = :attribute
                            AND LENGTH(eaov.value) >= :valMinLength
                            AND :queryText LIKE CONCAT('%', eaov.value, '%')
                        ORDER BY LENGTH(eaov.value) DESC",
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
     * @return QueryInterface|null
     */
    private function checkAttributeValueMatch(string &$queryText): ?QueryInterface
    {
        if (empty($queryText)) {
            return null;
        }

        foreach (self::FILTERABLE_ATTRIBUTES as $attribute) {
            //Match on values for this attribute, preferring to match longer values if possible
            $matchingValues = $this->queryTextContainsAttributeValue($attribute, $queryText);
            if (!empty($matchingValues)) {
                //For now we only take the first matching value, then strip it from the queryText and return a QueryInterface representing the filter
                $queryText = $this->stripFromQueryText($queryText, $matchingValues[0]);
                return $this->queryFactory->create(
                    'sitcAttributeValueQuery',
                    [
                        'attribute' => $attribute,
                        'value' => $matchingValues[0]
                    ]
                );
            }
        }
        return null;
    }

    /**
     * Strip $strip from $queryText (case-insensitively), returning the modified queryText
     * @param string $queryText
     * @param string $strip
     * @return string
     */
    public function stripFromQueryText(string $queryText, string $strip): string
    {
        return trim(str_ireplace($strip, '', $queryText));
    }

    /**
     * @param string $queryText
     * @return ItemInterface[]
     */
    public function getAutocompleteSuggestions(string $queryText): array
    {
        $suggestions = [];

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
            asort($catToCountMapping, SORT_NUMERIC);
            //asort puts the lowest count first, so flip it in the foreach
            foreach (array_reverse($catToCountMapping, true) as $cat => $count) {
                $this->logger->info("Category aggregate {$cat} has {$count} results");
                //Add a suggestion for the aggregate
                $suggestions[] = $this->itemFactory->create([
                    'title' => $this->formatCategoryTerm($queryText, $cat),
                    'type' => TermsDataProvider::AUTOCOMPLETE_TYPE
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
        $catalog_category_entity_varchar = $this->resourceConn->getTableName('catalog_category_entity_varchar');
        $eav_attribute = $this->resourceConn->getTableName('eav_attribute');
        $eav_entity_type = $this->resourceConn->getTableName('eav_entity_type');
        return $this->resourceConn->getConnection()->fetchOne(
            "SELECT ccev.value FROM {$catalog_category_entity_varchar} ccev
                INNER JOIN {$eav_attribute} ea
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
}