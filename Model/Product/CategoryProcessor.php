<?php

namespace SITC\Sinchimport\Model\Product;

use Magento\Catalog\Model\Category;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\UrlRewrite\Model\UrlPersistInterface;

class CategoryProcessor
{
    /**
     * Categories id to object cache.
     *
     * @var array
     */
    protected $categoriesCache = [];

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory
     */
    protected $categoryColFactory;

    /**
     * @var \Magento\Catalog\Model\CategoryFactory
     */
    protected $categoryFactory;

    /**
     * @var CategoryUrlPathGenerator
     */

    protected $categoryUrlPathGenerator;

    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * CategoryProcessor constructor.
     * @param \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryColFactory
     * @param \Magento\Catalog\Model\CategoryFactory $categoryFactory
     * @param CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param UrlPersistInterface $urlPersist
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException
     */
    public function __construct(
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryColFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->categoryColFactory = $categoryColFactory;
        $this->categoryFactory = $categoryFactory;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->urlPersist = $urlPersist;

        $this->initCategories();
    }

    /**
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException
     */
    protected function initCategories()
    {
        set_time_limit(0);

        if (empty($this->categoriesCache)) {
            $collection = $this->categoryColFactory->create()
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('url_key')
                ->addAttributeToSelect('url_path')
                ->setOrder('level');

            // save category url_key and url_path
            foreach ($collection as $category) {
                if ($category->getId() != Category::TREE_ROOT_ID
                    && !in_array(
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

                    $category->setUrlPath(
                        $this->categoryUrlPathGenerator->getUrlPath($category)
                    );
                    $category->getResource()->saveAttribute(
                        $category, 'url_path'
                    );
                }

                $this->categoriesCache[$category->getId()] = $category;
            }

            // generate category url rewrites
            foreach ($this->categoriesCache as $category) {
                if ($category->getLevel() == 2) {
                    $this->urlPersist->replace($this->categoryUrlRewriteGenerator->generate($category));
                }
            }
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
        return isset($this->categoriesCache[$categoryId]) ? $this->categoriesCache[$categoryId] : null;
    }
}
