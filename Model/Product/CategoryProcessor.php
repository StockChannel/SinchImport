<?php

namespace SITC\Sinchimport\Model\Product;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Framework\Exception\LocalizedException;
use Magento\UrlRewrite\Model\Exception\UrlAlreadyExistsException;
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
     * @var CollectionFactory
     */
    protected $categoryColFactory;

    /**
     * @var CategoryFactory
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
     * @param CollectionFactory $categoryColFactory
     * @param CategoryFactory $categoryFactory
     * @param CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param UrlPersistInterface $urlPersist
     * @throws LocalizedException
     * @throws UrlAlreadyExistsException
     */
    public function __construct(
        CollectionFactory $categoryColFactory,
        CategoryFactory $categoryFactory,
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
     * @throws LocalizedException
     * @throws UrlAlreadyExistsException
     */
    protected function initCategories()
    {
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
     * @return Category|null
     */
    public function getCategoryById($categoryId)
    {
        return isset($this->categoriesCache[$categoryId]) ? $this->categoriesCache[$categoryId] : null;
    }
}
