<?php
/*
 * @author  Tigren Solutions <info@tigren.com>
 * @copyright Copyright (c) 2023 Tigren Solutions <https://www.tigren.com>. All rights reserved.
 * @license  Open Software License (“OSL”) v. 3.0
 */

namespace SITC\Sinchimport\Helper;

use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use \Magento\Framework\App\ResourceConnection;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Symfony\Component\Console\Output\ConsoleOutput;
use Zend_Log_Exception;

/**
 * Class Url
 * @package SITC\Sinchimport\Helper
 */
class Url extends AbstractHelper
{

    /**
     * @var CollectionFactory
     */
    protected $collectionCategory;

    /**
     * @var CategoryUrlRewriteGenerator
     */
    protected $categoryUrlRewriteGenerator;

    /**
     * @var CategoryUrlPathGenerator
     */
    protected CategoryUrlPathGenerator $categoryUrlPathGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $connection;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;
    private ConsoleOutput $output;


    /**
     * @param ConsoleOutput $output
     * @param StoreManagerInterface $storeManager
     * @param CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param ResourceConnection $connection
     * @param CollectionFactory $collectionCategory
     * @param UrlPersistInterface $urlPersist
     * @param CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator
     * @param Context $context
     */
    public function __construct(
        ConsoleOutput $output,
        StoreManagerInterface $storeManager,
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        ResourceConnection $connection,
        CollectionFactory $collectionCategory,
        UrlPersistInterface $urlPersist,
        CategoryUrlRewriteGenerator $categoryUrlRewriteGenerator,
        Context $context
    ) {
        parent::__construct($context);
        $this->urlPersist = $urlPersist;
        $this->categoryUrlRewriteGenerator = $categoryUrlRewriteGenerator;
        $this->collectionCategory = $collectionCategory;
        $this->connection = $connection;
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->storeManager = $storeManager;
        $this->output = $output;
    }

    /**
     * @return void
     * @throws Zend_Log_Exception
     */
    public function generateCategoryUrl()
    {

        try {
            $this->output->writeln("Begin generate category url");
            $this->connection->getConnection()->beginTransaction();
            $this->connection->getConnection()->delete('url_rewrite',
                ['is_autogenerated = ?' => 1, 'entity_type = ?' => 'category']);
            $categoryCollection = $this->_getCategoryCollection($this->storeManager->getStore()->getId());
            if ($categoryCollection->getSize()) {
                foreach ($categoryCollection as $category) {
                    $requestPath = $this->categoryUrlRewriteGenerator->generate($category, true);
                    $this->urlPersist->replace($requestPath);
                }
            }
            $this->output->writeln("Generate category url success with " . $categoryCollection->getSize() . ' categories');
            $this->connection->getConnection()->commit();
        } catch (\Exception $e) {
            $this->connection->getConnection()->rollBack();
            $this->output->writeln("Error when generate category url,please check the log");
            return;
        }
    }

    /**
     * @throws LocalizedException
     */
    private function _getCategoryCollection($storeId): Collection
    {
        $categoriesCollection = $this->collectionCategory->create()
            ->addAttributeToSelect('name')
            ->addAttributeToSelect('url_key')
            ->addAttributeToSelect('url_path');
        $categoriesCollection->addFieldToFilter('level', 2)->setOrder('level', 'ASC');
        $rootCategoryId = $this->_getRootCategoryId($storeId);
        if ($rootCategoryId > 0) {
            $categoriesCollection->addAttributeToFilter('path', array('like' => "1/{$rootCategoryId}/%"));
        }
        return $categoriesCollection;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function _getRootCategoryId($storeId): int
    {
        $store = $this->storeManager->getStore($storeId);
        return $store->getRootCategoryId();
    }
}