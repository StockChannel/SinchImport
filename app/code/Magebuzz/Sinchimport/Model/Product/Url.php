<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model\Product;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\ResourceConnection;
use Magento\ImportExport\Model\Import as ImportExport;
use Magento\Store\Model\Store;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;
use Magento\UrlRewrite\Model\UrlFinderInterface;

class Url extends \Magento\Catalog\Model\Product\Url
{
    /** @var array */
    protected $products = [];

    /**
     * @var Product\CategoryProcessor
     */
    protected $categoryProcessor;

    /** @var UrlFinderInterface */
    protected $urlFinder;

    /** @var UrlPersistInterface */
    protected $urlPersist;

    /** @var UrlRewriteFactory */
    protected $urlRewriteFactory;

    /** @var \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator */
    protected $productUrlPathGenerator;

    /** @var array */
    protected $storesCache = [];

    /**
     * @param \Magento\Framework\UrlFactory $urlFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Filter\FilterManager $filter
     * @param \Magento\Framework\Session\SidResolverInterface $sidResolver
     * @param \Magento\UrlRewrite\Model\UrlFinderInterface $urlFinder
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\UrlFactory $urlFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Filter\FilterManager $filter,
        \Magento\Framework\Session\SidResolverInterface $sidResolver,
        \Magento\UrlRewrite\Model\UrlFinderInterface $urlFinder,
        \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $productUrlPathGenerator,
        \Magebuzz\Sinchimport\Model\Product\CategoryProcessor $categoryProcessor,
        UrlPersistInterface $urlPersist,
        UrlRewriteFactory $urlRewriteFactory,
        UrlFinderInterface $urlFinder,
        array $data = []
    ) {
        parent::__construct($urlFactory, $storeManager, $filter, $sidResolver, $urlFinder, $data);
        $this->categoryProcessor = $categoryProcessor;
        $this->urlPersist = $urlPersist;
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->urlRewriteFactory = $urlRewriteFactory;
        $this->urlFinder = $urlFinder;
    }

    /**
     * Get url resource instance
     */
    protected function _getResource()
    {
        return \Magento\Framework\App\ObjectManager::getInstance()->get('Magebuzz\Sinchimport\Model\ResourceModel\Product\Url');
    }

    /**
     * Retrieve stores array or store model
     */
    public function getStores($storeId = null)
    {
        if ($storeId) {
            return $this->storeManager->getStore($storeId);
        }

        return $this->storeManager->getStores($storeId);
    }

    /**
     * Refresh all rewrite urls for some store or for all stores
     * Used to make full reindexing of url rewrites
     */
    public function refreshRewrites($storeId = null)
    {
        if (is_null($storeId)) {
            foreach ($this->getStores() as $store) {
                $this->storesCache[] = $store->getId();
                $this->refreshRewrites($store->getId());
            }
            return $this;
        }

        $this->refreshProductRewrites($storeId);

        return $this;
    }

    /**
     * Refresh all product rewrites for designated store
     */
    public function refreshProductRewrites($storeId)
    {
        $lastEntityId = 0;
        $process = true;

        while ($process == true) {
            $this->products = $this->_getResource()->getProductsByStore($storeId, $lastEntityId);
            if (!$this->products) {
                $process = false;
                break;
            }

            $productUrls = $this->generateUrls($storeId);

            if ($productUrls) {
                try {
                    $this->urlPersist->replace($productUrls);
                } catch (\Exception $e) {
                    // do nothing
                }
            }
        }

        return $this;
    }

    /**
     * Generate product url rewrites
     *
     * @return \Magento\UrlRewrite\Service\V1\Data\UrlRewrite[]
     */
    protected function generateUrls($storeId)
    {
        /**
         * @var $urls \Magento\UrlRewrite\Service\V1\Data\UrlRewrite[]
         */
        $urls = array_merge(
            $this->canonicalUrlRewriteGenerate($storeId),
            $this->categoriesUrlRewriteGenerate($storeId),
            $this->currentUrlRewritesRegenerate($storeId)
        );

        /* Reduce duplicates. Last wins */
        $result = [];
        foreach ($urls as $url) {
            $result[$url->getTargetPath() . '-' . $url->getStoreId()] = $url;
        }

        $this->products = [];

        return $result;
    }

    /**
     * Generate list based on store view
     *
     * @return UrlRewrite[]
     */
    protected function canonicalUrlRewriteGenerate($storeId)
    {
        $urls = [];
        foreach ($this->products as $product) {
            if ($this->productUrlPathGenerator->getUrlPath($product)) {
                $urls[] = $this->urlRewriteFactory->create()
                    ->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($product->getId())
                    ->setRequestPath($this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId))
                    ->setTargetPath($this->productUrlPathGenerator->getCanonicalUrlPath($product))
                    ->setStoreId($storeId);
            }
        }

        return $urls;
    }

    /**
     * Generate list based on categories
     *
     * @return UrlRewrite[]
     */
    protected function categoriesUrlRewriteGenerate($storeId)
    {
        $urls = [];
        foreach ($this->products as $product) {
            foreach ($product->getCategoryIds() as $categoryId) {
                $category = $this->categoryProcessor->getCategoryById($categoryId);
                $requestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId, $category);
                $urls[] = $this->urlRewriteFactory->create()
                    ->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($product->getId())
                    ->setRequestPath($requestPath)
                    ->setTargetPath($this->productUrlPathGenerator->getCanonicalUrlPath($product, $category))
                    ->setStoreId($storeId)
                    ->setMetadata(['category_id' => $category->getId()]);
            }
        }

        return $urls;
    }

    /**
     * Generate list based on current rewrites
     *
     * @return UrlRewrite[]
     */
    protected function currentUrlRewritesRegenerate($storeId)
    {
        $currentUrlRewrites = $this->urlFinder->findAllByData(
            [
                UrlRewrite::STORE_ID => array_keys($this->storesCache),
                UrlRewrite::ENTITY_ID => array_keys($this->products),
                UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            ]
        );
        $urlRewrites = [];
        foreach ($currentUrlRewrites as $currentUrlRewrite) {
            $category = $this->retrieveCategoryFromMetadata($currentUrlRewrite);
            if ($category === false) {
                continue;
            }
            $url = $currentUrlRewrite->getIsAutogenerated()
                ? $this->generateForAutogenerated($currentUrlRewrite, $category)
                : $this->generateForCustom($currentUrlRewrite, $category);
            $urlRewrites = array_merge($urlRewrites, $url);
        }

        return $urlRewrites;
    }
}
