<?php


namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Logger\Logger;


/**
 * Interceptor on Elasticsuite indexing to populate values for product stock attribute
 *
 * @package SITC\Sinchimport\Plugin\Elasticsuite
 */
class InventoryData
{
    const IN_STOCK_FILTER_CODE = 'sinch_in_stock';

    /**
     * @var ProductRepositoryInterface
     */
    private  $productRepository;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * InventoryData constructor.
     * @param ProductRepositoryInterface $productRepository
     * @param Logger $logger
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        Logger $logger,
        StoreManagerInterface $storeManager
    ){
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
    }

    /**
     * Add stock status to product data so we can filter on it
     *
     * @param \Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\InventoryData $subject
     * @param $result
     */
    public function afterAddData(\Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\InventoryData $subject, $result)
    {
        $count = 0;
        if (empty($result)) {
            $this->logger->info("Result is empty");
        }
        foreach ($result as $key => $value) {
            $prodId = $key;
            try {
                /** @var Product $product */
                $product = $this->productRepository->getById($prodId);
                $isInStock = $value['is_in_stock'];
                $product->setData(self::IN_STOCK_FILTER_CODE, $isInStock);
                $this->productRepository->save($product);
                $count++;
            } catch (Exception $e) {
                $this->logger->info("Couldn't find product with ID $prodId");
            }
        }

        $this->logger->info("Processed stock filter for $count products");
    }
}