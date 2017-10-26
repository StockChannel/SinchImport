<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model\Product;

use Magento\Catalog\Model\Category;
use Magento\CatalogUrlRewrite\Model\Category\ChildrenCategoriesProvider;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\CatalogUrlRewrite\Service\V1\StoreViewService;
use Magento\UrlRewrite\Model\UrlPersistInterface;

class CategoryProcessor
{
    /**
     * Delimiter in category path.
     */
    const DELIMITER_CATEGORY = '/';
    
    /** @var CategoryUrlPathGenerator */
    protected $categoryUrlPathGenerator;
    
    /** @var \Magento\CatalogUrlRewrite\Model\Category\ChildrenCategoriesProvider */
    protected $childrenCategoriesProvider;
    
    /** @var StoreViewService */
    protected $storeViewService;
    
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $categoryColFactory;
    
    /**
     * Categories id to object cache.
     *
     * @var array
     */
    protected $categoriesCache = [];
    
    /**
     * Instance of catalog category factory.
     *
     * @var \Magento\Catalog\Model\CategoryFactory
     */
    protected $categoryFactory;
    
    /** @var CategoryUrlRewriteGenerator */
    protected $categoryUrlRewriteGenerator;
    
    /** @var UrlPersistInterface */
    protected $urlPersist;
    
    /**
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryColFactory
     * @param \Magento\Catalog\Model\CategoryFactory                          $categoryFactory
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryColFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        ChildrenCategoriesProvider $childrenCategoriesProvider,
        StoreViewService $storeViewService,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->categoryColFactory          = $categoryColFactory;
        $this->categoryFactory             = $categoryFactory;
        $this->categoryUrlPathGenerator    = $categoryUrlPathGenerator;
        $this->childrenCategoriesProvider  = $childrenCategoriesProvider;
        $this->storeViewService            = $storeViewService;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->urlPersist                  = $urlPersist;
        $this->initCategories();
    }
    
    /**
     * @return $this
     */
    protected function initCategories()
    {
        if (empty($this->categoriesCache)) {
            $collection = $this->categoryColFactory->create();
            $collection->addAttributeToSelect('name')
                ->addAttributeToSelect('url_key')
                ->addAttributeToSelect('url_path')
                ->setOrder('level');
            
            /* @var $collection \Magento\Catalog\Model\ResourceModel\Category\Collection */
            foreach ($collection as $category) {
                // save category url_key
                if ($category->getId() != Category::TREE_ROOT_ID
                    && ! in_array(
                        $category->getParentId(),
                        [Category::ROOT_CATEGORY_ID, Category::TREE_ROOT_ID]
                    )
                ) {
                    $category->setUrlKey(
                        $this->categoryUrlPathGenerator->getUrlKey($category)
                    );
                    $category->getResource()->saveAttribute(
                        $category, 'url_key'
                    );
                }
            }
            
            $urlRewrites = [];
            
            $collection->clear()->load();
            /* @var $collection \Magento\Catalog\Model\ResourceModel\Category\Collection */
            foreach ($collection as $category) {
                // save category url_path
                if ($category->getId() != Category::TREE_ROOT_ID
                    && ! in_array(
                        $category->getParentId(),
                        [Category::ROOT_CATEGORY_ID, Category::TREE_ROOT_ID]
                    )
                ) {
                    $category->setUrlPath(
                        $this->categoryUrlPathGenerator->getUrlPath($category)
                    );
                    $category->getResource()->saveAttribute(
                        $category, 'url_path'
                    );
                    
                    $urlRewrites = array_merge(
                        $urlRewrites,
                        $this->categoryUrlRewriteGenerator->generate($category)
                    );
                }
                
                $this->categoriesCache[$category->getId()] = $category;
            }
            
            $this->urlPersist->replace($urlRewrites);
        }
        
        return $this;
    }
    
    /**
     * Get category by Id
     *
     * @param int $categoryId
     *
     * @return \Magento\Catalog\Model\Category|null
     */
    public function getCategoryById($categoryId)
    {
        return isset($this->categoriesCache[$categoryId])
            ? $this->categoriesCache[$categoryId] : null;
    }
}
