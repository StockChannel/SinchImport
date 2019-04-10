<?php

namespace SITC\Sinchimport\Model\Product;

use Magento\Framework\App\ObjectManager;
use Magento\Catalog\Model\Category;
use Magento\UrlRewrite\Model\OptionProvider;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;

class Url extends \Magento\Catalog\Model\Product\Url
{
    /** @var array */
    protected $products = [];

    /**
     * @var \Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor
     */
    protected $categoryProcessor;

    /**
     * @var \SITC\Sinchimport\Logger\Logger
     */
    protected $sinchLogger;

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
     * @var \Symfony\Component\Console\Output\ConsoleOutput
     */
    protected $_outPut;

    /**
     * Url constructor.
     *
     * @param \Magento\Framework\UrlFactory $urlFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Filter\FilterManager $filter
     * @param \Magento\Framework\Session\SidResolverInterface $sidResolver
     * @param \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $productUrlPathGenerator
     * @param \SITC\Sinchimport\Model\Product\CategoryProcessor $categoryProcessor
     * @param \SITC\Sinchimport\Logger\Logger $sinchLogger
     * @param UrlPersistInterface $urlPersist
     * @param UrlRewriteFactory $urlRewriteFactory
     * @param UrlFinderInterface $urlFinder
     * @param \Symfony\Component\Console\Output\ConsoleOutput $output
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\UrlFactory $urlFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Filter\FilterManager $filter,
        \Magento\Framework\Session\SidResolverInterface $sidResolver,
        \Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator $productUrlPathGenerator,
        \SITC\Sinchimport\Model\Product\CategoryProcessor $categoryProcessor,
        \SITC\Sinchimport\Logger\Logger $sinchLogger,
        UrlPersistInterface $urlPersist,
        UrlRewriteFactory $urlRewriteFactory,
        UrlFinderInterface $urlFinder,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        array $data = []
    ) {
        parent::__construct(
            $urlFactory, $storeManager, $filter, $sidResolver, $urlFinder, $data
        );
        $this->categoryProcessor = $categoryProcessor;
        $this->sinchLogger = $sinchLogger;
        $this->urlPersist = $urlPersist;
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->urlRewriteFactory = $urlRewriteFactory;
        $this->urlFinder = $urlFinder;
        $this->_outPut = $output;
    }

    /**
     * @param null $storeId
     * @return $this
     * @throws \Magento\Framework\Exception\NoSuchEntityException
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
     * @param null $storeId
     * @return \Magento\Store\Api\Data\StoreInterface|\Magento\Store\Api\Data\StoreInterface[]
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStores($storeId = null)
    {
        if ($storeId) {
            return $this->storeManager->getStore($storeId);
        }
        return $this->storeManager->getStores($storeId);
    }

    /**
     * Refresh all product rewrites for designated store
     * @param $storeId
     * @return Url
     */
    public function refreshProductRewrites($storeId)
    {
        $lastEntityId = 0;
        $process      = true;
        $step         = 0;
        while ($process == true) {

            $this->products = $this->_getResource()->getProductsByStore(
                $storeId, $lastEntityId
            );

            if (!$this->products) {
                $process = false;
                break;
            }

            $productUrls = $this->generateUrls($storeId);
            if ($productUrls) {
                try {
                    $this->urlPersist->replace($productUrls);
                } catch (\Exception $e) {
                    $logString = "[ERROR] " . $e->getMessage();
                    $this->sinchLogger->info($logString);;
                }
            }
            //display in run command
            $step++;
            $this->_outPut->write(".");
            if ($step > 38){
                $this->_outPut->writeln("");
                $step = 0;
            };
        }

        return $this;
    }

    /**
     * Get url resource instance
     */
    protected function _getResource()
    {
        return ObjectManager::getInstance()->get(
            'SITC\Sinchimport\Model\ResourceModel\Product\Url'
        );
    }

    /**
     * @param $storeId
     * @return array
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
                $urlTargetPath = $this->productUrlPathGenerator->getCanonicalUrlPath($product);
                $urlRequestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId);
                $urls[] = $this->urlRewriteFactory->create()
                    ->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($product->getId())
                    ->setRequestPath($urlRequestPath)
                    ->setTargetPath($urlTargetPath)
                    ->setStoreId($storeId);
            }
        }
        return $urls;
    }

    /**
     * Generate list based on categories
     *
     * @param $storeId
     * @return UrlRewrite[]
     */
    protected function categoriesUrlRewriteGenerate($storeId)
    {
        $urls = [];
        foreach ($this->products as $product) {
            foreach ($product->getCategoryIds() as $categoryId) {
                $category = $this->categoryProcessor->getCategoryById($categoryId);
                $requestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix(
                    $product, $storeId, $category
                );
                $urls[] = $this->urlRewriteFactory->create()
                    ->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($product->getId())
                    ->setRequestPath($requestPath)
                    ->setTargetPath(
                        $this->productUrlPathGenerator->getCanonicalUrlPath(
                            $product, $category
                        )
                    )
                    ->setStoreId($storeId)
                    ->setMetadata(['category_id' => $category->getId()]);
            }

        }
        return $urls;
    }

    /**
     * Generate list based on current rewrites
     *
     * @param $storeId
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

    /**
     * @param UrlRewrite $url
     * @return Category|null|bool
     */
    protected function retrieveCategoryFromMetadata($url)
    {
        $metadata = $url->getMetadata();
        if (isset($metadata['category_id'])) {
            $category = $this->categoryProcessor->getCategoryById($metadata['category_id']);
            return $category === null ? false : $category;
        }
        return null;
    }

    /**
     * @param UrlRewrite $url
     * @param Category $category
     * @return array
     */
    protected function generateForAutogenerated($url, $category)
    {
        $storeId = $url->getStoreId();
        $productId = $url->getEntityId();
        if (isset($this->products[$productId][$storeId])) {
            $product = $this->products[$productId][$storeId];
            if (!$product->getData('save_rewrites_history')) {
                return [];
            }
            $targetPath = $this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId, $category);
            if ($url->getRequestPath() === $targetPath) {
                return [];
            }
            return [
                $this->urlRewriteFactory->create()
                    ->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($productId)
                    ->setRequestPath($url->getRequestPath())
                    ->setTargetPath($targetPath)
                    ->setRedirectType(OptionProvider::PERMANENT)
                    ->setStoreId($storeId)
                    ->setDescription($url->getDescription())
                    ->setIsAutogenerated(0)
                    ->setMetadata($url->getMetadata())
            ];
        }
        return [];
    }

    /**
     * @param UrlRewrite $url
     * @param Category $category
     * @return array
     */
    protected function generateForCustom($url, $category)
    {
        $storeId = $url->getStoreId();
        $productId = $url->getEntityId();
        if (isset($this->products[$productId][$storeId])) {
            $product = $this->products[$productId][$storeId];
            $targetPath = $url->getRedirectType()
                ? $this->productUrlPathGenerator->getUrlPathWithSuffix($product, $storeId, $category)
                : $url->getTargetPath();
            if ($url->getRequestPath() === $targetPath) {
                return [];
            }
            return [
                $this->urlRewriteFactory->create()
                    ->setEntityType(ProductUrlRewriteGenerator::ENTITY_TYPE)
                    ->setEntityId($productId)
                    ->setRequestPath($url->getRequestPath())
                    ->setTargetPath($targetPath)
                    ->setRedirectType($url->getRedirectType())
                    ->setStoreId($storeId)
                    ->setDescription($url->getDescription())
                    ->setIsAutogenerated(0)
                    ->setMetadata($url->getMetadata())
            ];
        }
        return [];
    }
}