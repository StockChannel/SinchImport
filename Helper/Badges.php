<?php

namespace SITC\Sinchimport\Helper;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\Exception\NoSuchEntityException;
use SITC\Sinchimport\Logger\Logger;

class Badges
{

    const BADGE_BESTSELLER = "sinch_bestseller";
    const BADGE_HOT_PRODUCT = "sinch_hot_product";
    const BADGE_NEW = "sinch_new";
    const BADGE_POPULAR = "sinch_popular";
    const BADGE_RECOMMENDED = "sinch_recommended";

    const BADGE_TYPES = [
        self::BADGE_BESTSELLER => self::YEARLY_SALES,
        self::BADGE_HOT_PRODUCT => self::MONTHLY_SALES,
        self::BADGE_NEW => self::RELEASE_DATE,
        self::BADGE_POPULAR => self::SCORE,
        self::BADGE_RECOMMENDED => self::SEARCHES,
    ];

    const SCORE = "sinch_score";
    const YEARLY_SALES = "sinch_popularity_year";
    const MONTHLY_SALES = "sinch_popularity_month";
    const SEARCHES = "sinch_searches";
    const RELEASE_DATE = "sinch_release_date";

    private Data $helper;
    private ProductRepository $productRepository;
    private Logger $logger;

    public function __construct(
        Data $helper,
        ProductRepository $productRepository,
        Logger $logger
    ) {
        $this->helper = $helper;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
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
     * @param Product $product
     * @param string $badgeType
     */
    public function flagBadgeProduct(Product $product, string $badgeType)
    {
        $product->setData($badgeType, true);
        $this->logger->info($product->getSku() . " | " . $badgeType . " | " . $product->getData($badgeType));
    }

    /**
     * @param ProductCollection $products
     * @return array
     * @throws NoSuchEntityException
     */
    public function getProductsForBadges(ProductCollection $products): array
    {
        if ($products->getCurPage() > 1) {
            return [];
        }
        $badgeProducts = [];
        $productArr = array_column($products->getData(), 'entity_id');

        foreach (self::BADGE_TYPES as $badgeType => $attrCode) {
            $productArr = array_merge([], $productArr);
            usort($productArr, function ($a, $b) use ($attrCode) {
                $productA = $this->productRepository->getById($a);
                $productB = $this->productRepository->getById($b);
                $attrValueA = is_array($productA->getData($attrCode)) ? $productA->getData($attrCode)[0] : (string)$productA->getData($attrCode);
                $attrValueB = is_array($productB->getData($attrCode)) ? $productB->getData($attrCode)[0] : (string)$productB->getData($attrCode);
                if (intval($attrValueA) == intval($attrValueB)) return 0;
                return (intval($attrValueA) > intval($attrValueB)) ? 1 : -1;
            });

            $prodId = $productArr[0];
            //Pop first element off the array, so we don't mark the same product for multiple badges
            array_shift($productArr);
            $product = $this->productRepository->getById($prodId);
            $badgeProducts[] = [$product, $badgeType];
        }
        return $badgeProducts;
    }
}
