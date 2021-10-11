<?php

namespace SITC\Sinchimport\Helper;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use SITC\Sinchimport\Logger\Logger;

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
    private ProductRepository $productRepository;
    private Logger $logger;
    private CacheInterface $cache;
    private Json $serializer;
    private Context $context;

    /**
     * @param Data $helper
     * @param ProductRepository $productRepository
     * @param Logger $logger
     * @param CacheInterface $cache
     * @param Json $serializer
     * @param Context $context
     */
    public function __construct(
        Data $helper,
        ProductRepository $productRepository,
        Logger $logger,
        CacheInterface $cache,
        Json $serializer,
        Context $context
    ) {
        parent::__construct($context);

        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * @param string $badgeType
     * @return string|null
     */
    public function getBadgeImageUrl(string $badgeType)
    {
        switch ($badgeType) {
            case self::BADGE_BESTSELLER:
                return $this->helper->getStoreConfig('sinchimport/badges/bestseller');
            case self::BADGE_HOT_PRODUCT:
                return $this->helper->getStoreConfig('sinchimport/badges/hot_product');
            case self::BADGE_NEW:
                return $this->helper->getStoreConfig('sinchimport/badges/new');
            case self::BADGE_POPULAR:
                return $this->helper->getStoreConfig('sinchimport/badges/popular');
            case self::BADGE_RECOMMENDED:
                return $this->helper->getStoreConfig('sinchimport/badges/recommended');
        }

        return null;
    }

    /**
     * @param string $badgeName
     * @return string
     */
    public function getFormattedBadgeTitle(string $badgeName): string
    {
        $badgeTitle = str_replace('sinch', '', $badgeName);
        $badgeTitle = str_replace('_', ' ', $badgeTitle);
        return ucwords($badgeTitle);
    }

    /**
     * @param array $badgeProducts
     * @param array $loadedCollectionIds
     */
    private function saveBadgeProducts(array $badgeProducts, array $loadedCollectionIds)
    {
        $serializedProductIds = $this->serializer->serialize($badgeProducts);
        $cacheId = self::CACHE_ID . '|' . implode(",", $loadedCollectionIds);
        //Save IDs of badge products to cache with 5 min lifetime
        $this->cache->save($serializedProductIds, $cacheId, ['SITC_Sinchimport'], 60);
    }

    /**
     * @param ProductCollection $products
     * @return mixed
     */
    public function loadCachedBadgeProducts(ProductCollection $products)
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
    private function generateProductsForBadges(ProductCollection $products)
    {
        if ($products->getCurPage() > 1) {
            return;
        }
        $badgeProducts = [];
        $productArr = array_column($products->getData(), 'entity_id');

        foreach (self::BADGE_TYPES as $badgeType => $attrCode) {
            $productArr = array_merge([], $productArr);
            usort($productArr, function ($a, $b) use ($attrCode) {
                try {
                    $productA = $this->productRepository->getById($a);
                } catch (NoSuchEntityException $e) {
                    $this->logger->info($e->getMessage());
                    return 0;
                }
                try {
                    $productB = $this->productRepository->getById($b);
                } catch (NoSuchEntityException $e) {
                    $this->logger->info($e->getMessage());
                    return 0;
                }
                $attrValueA = is_array($productA->getData($attrCode)) ? $productA->getData($attrCode)[0] : (string)$productA->getData($attrCode);
                $attrValueB = is_array($productB->getData($attrCode)) ? $productB->getData($attrCode)[0] : (string)$productB->getData($attrCode);
                if (intval($attrValueA) == intval($attrValueB)) return 0;
                return (intval($attrValueA) > intval($attrValueB)) ? 1 : -1;
            });

            $prodId = $productArr[0] ?? null;
            //Pop first element off the array, so we don't mark the same product for multiple badges
            array_shift($productArr);
            try {
                $product = $this->productRepository->getById($prodId);
            } catch(NoSuchEntityException $e) {
                $this->logger->info($e->getMessage());
                continue;
            }
            $badgeProducts[$badgeType] = $product->getId();
        }
        $this->saveBadgeProducts($badgeProducts, $products->getLoadedIds());
    }
}
