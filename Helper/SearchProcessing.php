<?php

namespace SITC\Sinchimport\Helper;

use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Smile\ElasticsuiteCore\Api\Search\Request\ContainerConfigurationInterface;
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

    private const FILTERABLE_ATTRIBUTES = [
        'manufacturer',
        'sinch_family'
    ];
    //Only match attribute values at least this long
    private const ATTRIBUTE_VALUE_MIN_LENGTH = 3;

    private Session $customerSession;
    private QueryFactory $queryFactory;
    private Index $thesaurus;

    public function __construct(Context $context, Session $customerSession, QueryFactory\Proxy $queryFactory, Index\Proxy $thesaurus)
    {
        parent::__construct($context);
        $this->customerSession = $customerSession;
        $this->queryFactory = $queryFactory;
        $this->thesaurus = $thesaurus;
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
        $regex = self::QUERY_REGEXES[$filterType];
        //Attribute is a special case as it requires runtime generation
        if ($filterType == self::FILTER_TYPE_ATTRIBUTE) {
            $attributeValues = [];
            $valuesText = implode("|", $attributeValues);
            $regex = "/(?<query>.*)(?<value>{$valuesText})/u";
        }
        if (preg_match(self::QUERY_REGEXES[$filterType], $queryText, $matches, PREG_SET_ORDER) >= 1) {
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
}