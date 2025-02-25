<?php
namespace SITC\Sinchimport\Observer;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use SITC\Sinchimport\Helper\Badges;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Plugin\Catalog\CategoryView;

class ProductCollectionLoadAfter implements ObserverInterface
{
    private Data $helper;
    private Badges $badgeHelper;
    private Logger $logger;
    private CategoryView $categoryViewPlugin;

    public function __construct(
        Data $helper,
        Badges $badgeHelper,
        Logger $logger,
        CategoryView $categoryViewPlugin
    ) {
        $this->helper = $helper;
        $this->badgeHelper = $badgeHelper;
        $this->logger = $logger;
        $this->categoryViewPlugin = $categoryViewPlugin;
    }


    public function execute(Observer $observer)
    {
        /** @var Collection $filteredProductCollection */
        $filteredProductCollection = $observer->getCollection();
        $productCollection = clone $filteredProductCollection;

        if(!$this->helper->isProductVisibilityEnabled() || $this->helper->isModuleEnabled('Smile_ElasticsuiteCatalog')){
            $this->loadCachedProducts($filteredProductCollection);
            return; //No filtering if the feature isn't enabled or with Elasticsuite enabled (as thats handled by Plugin\Elasticsuite\ContainerConfiguration)
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();
        $filteredProductCollection->removeAllItems();


        /** @var Product $product */
        foreach ($productCollection as $product) {
            if($this->helper->checkProductVisibility($product, $account_group_id)) {
                $filteredProductCollection->addItem($product);
            }
        }

        $this->loadCachedProducts($filteredProductCollection);
    }

    private function loadCachedProducts(Collection $productCollection): void
    {
        if ($this->helper->badgesEnabled()) {
            $enabled = 0;
            foreach (Badges::BADGE_TYPES as $badgeType => $_) {
                if ($this->badgeHelper->badgeEnabled($badgeType)) {
                    $enabled++;
                }
            }
            if ($productCollection->getSize() > $enabled) {
                $badgeProducts = $this->badgeHelper->loadCachedBadgeProducts($productCollection);
                $this->categoryViewPlugin->setProductCollection($badgeProducts);
            }
        }
    }
}
