<?php

namespace SITC\Sinchimport\Helper;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Monolog\Handler\ChromePHPHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class Badges extends AbstractHelper
{

    //Badge codes
    const BADGE_BESTSELLER = "sinch_bestseller";
    const BADGE_HOT_PRODUCT = "sinch_hot_product";
    const BADGE_NEW = "sinch_new";
    const BADGE_POPULAR = "sinch_popular";
    const BADGE_RECOMMENDED = "sinch_recommended";

    //Attribute codes
    const SCORE = "sinch_score";
    const YEARLY_SALES = "sinch_popularity_year";
    const MONTHLY_SALES = "sinch_popularity_month";
    const SEARCHES = "sinch_searches";
    const RELEASE_DATE = "sinch_release_date";

    //Array of badge types mapped to attribute codes
    const BADGE_TYPES = [
        self::BADGE_BESTSELLER => self::YEARLY_SALES,
        self::BADGE_HOT_PRODUCT => self::MONTHLY_SALES,
        self::BADGE_NEW => self::RELEASE_DATE,
        self::BADGE_POPULAR => self::SCORE,
        self::BADGE_RECOMMENDED => self::SEARCHES,
    ];

    const CACHE_ID = "sinch_badge_products";

    private Data $helper;
    private Logger $logger;
    private CacheInterface $cache;
    private Json $serializer;

    private ResourceConnection $resourceConn;


    public function __construct(
        Data $helper,
        CacheInterface $cache,
        Json $serializer,
        Context $context,
        ResourceConnection $resourceConn
    ) {
        parent::__construct($context);

        $this->helper = $helper;
        $this->logger = new Logger("badges");
        $this->logger->pushHandler(new FirePHPHandler());
        $this->logger->pushHandler(new ChromePHPHandler());
        if ($this->helper->getStoreConfig('sinchimport/general/debug') != 1) {
            $this->logger->pushHandler(new NullHandler());
        }
        $this->cache = $cache;
        $this->serializer = $serializer;
        $this->resourceConn = $resourceConn;
    }

    /**
     * @param string $badgeType
     * @return string|null
     */
    public function getBadgeContent(string $badgeType): ?string
    {
        return match ($badgeType) {
            self::BADGE_BESTSELLER => $this->helper->getStoreConfig('sinchimport/badges/bestseller'),
            self::BADGE_HOT_PRODUCT => $this->helper->getStoreConfig('sinchimport/badges/hot_product'),
            self::BADGE_NEW => $this->helper->getStoreConfig('sinchimport/badges/new'),
            self::BADGE_POPULAR => $this->helper->getStoreConfig('sinchimport/badges/popular'),
            self::BADGE_RECOMMENDED => $this->helper->getStoreConfig('sinchimport/badges/recommended'),
            default => null,
        };
    }

    public function badgeEnabled(string $badgeType): bool
    {
        return match ($badgeType) {
            self::BADGE_BESTSELLER => $this->helper->getStoreConfig('sinchimport/badges/enable_bestseller'),
            self::BADGE_HOT_PRODUCT => $this->helper->getStoreConfig('sinchimport/badges/enable_hot_product'),
            self::BADGE_NEW => $this->helper->getStoreConfig('sinchimport/badges/enable_new'),
            self::BADGE_POPULAR => $this->helper->getStoreConfig('sinchimport/badges/enable_popular'),
            self::BADGE_RECOMMENDED => $this->helper->getStoreConfig('sinchimport/badges/enable_recommended'),
            default => false,
        };
    }

    /**
     * @param string $badgeName
     * @return string
     */
    public function getFormattedBadgeTitle(string $badgeName): string
    {
        $badgeTitle = str_replace('sinch_', '', $badgeName);
        $badgeTitle = str_replace('_', ' ', $badgeTitle);
        return ucwords($badgeTitle);
    }

    /**
     * @param array $badgeProducts
     * @param array $loadedCollectionIds
     */
    private function saveBadgeProducts(array $badgeProducts, array $loadedCollectionIds): void
    {
        $serializedProductIds = $this->serializer->serialize($badgeProducts);
        $cacheId = self::CACHE_ID . '|' . implode(",", $loadedCollectionIds);
        //Save IDs of badge products to cache with 5 min lifetime
        $this->cache->save($serializedProductIds, $cacheId, ['SITC_Sinchimport'], 60);
    }

    /**
     * @param ProductCollection|null $products
     * @return mixed
     */
    public function loadCachedBadgeProducts(ProductCollection $products = null): mixed
    {
        $cacheId = self::CACHE_ID . '|' . implode("," ,$products->getLoadedIds());
        $cachedBadges = $this->cache->load($cacheId);
        if (empty($cachedBadges)) {
            $this->generateProductsForBadges($products);
            $cachedBadges = $this->cache->load($cacheId);
        }

        if (empty($cachedBadges))
            return [];

        return $this->serializer->unserialize($cachedBadges);
    }

    /**
     * @param ProductCollection $products
     */
    private function generateProductsForBadges(ProductCollection $products): void
    {
        if ($products->getCurPage() > 1) {
            return;
        }
        $badgeProducts = [];
        $productArr = array_column($products->getData(), 'entity_id');

        foreach (self::BADGE_TYPES as $badgeType => $attrCode) {
            $inClause = implode(",", array_fill(0, count($productArr), '?'));
            $tableName = $this->resourceConn->getTableName(
                $attrCode == 'sinch_release_date' ? 'catalog_product_entity_datetime' : 'catalog_product_entity_int'
            );
            $pairs = $this->resourceConn->getConnection()->fetchPairs(
                "SELECT entity_id, value FROM $tableName WHERE entity_id IN ($inClause) AND attribute_id = ?
                    ORDER BY value DESC",
                array_merge($productArr, [$this->helper->getProductAttributeId($attrCode)])
            );
            // Exclude products already selected for other badges
            $pairs = array_filter($pairs, function ($value, $entity_id) use ($badgeProducts) {
                return !in_array($entity_id, $badgeProducts);
            }, ARRAY_FILTER_USE_BOTH);

            if (!empty($pairs)) {
                $highestValKey = array_key_first($pairs);
                if ($pairs[$highestValKey] <= 0 || ($attrCode == 'sinch_release_date' && $pairs[$highestValKey] == '0000-00-00 00:00:00')) {
                    $this->logger->info("Highest value for $badgeType ($attrCode) <= 0 (or == '0000-00-00 00:00:00'), skipping");
                } else {
                    $badgeProducts[$badgeType] = $highestValKey;
                    $ids = implode(', ', array_keys($pairs));
                    $this->logger->info("$badgeType ($attrCode): [$ids]");
                    $values = implode(', ', array_values($pairs));
                    $this->logger->info("$badgeType ($attrCode) values: [$values]");
                }
            }
        }
        $this->saveBadgeProducts($badgeProducts, $products->getLoadedIds());
    }
}
