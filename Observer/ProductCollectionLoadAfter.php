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

        //TODO: move up in method so the return on L36 doesn't prevent this code from running
        $isExperimentalSearch = $this->helper->experimentalSearchEnabled() && $filteredProductCollection->getSize() > 4;

        if ($isExperimentalSearch) {
            $badgeProducts = $this->badgeHelper->loadCachedBadgeProducts($filteredProductCollection);
            $this->categoryViewPlugin->setProductCollection($badgeProducts);
        }


        if(!$this->helper->isProductVisibilityEnabled() || $this->helper->isModuleEnabled('Smile_ElasticsuiteCatalog')){
            return; //No filtering if the feature isn't enabled or with Elasticsuite enabled (as thats handled by Plugin\Elasticsuite\ContainerConfiguration)
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();
        $filteredProductCollection->removeAllItems();

        /** @var Product $product */
        foreach ($productCollection as $product) {
            $sinch_restrict = $product->getSinchRestrict();

            if(empty($sinch_restrict)){ //If sinch_restrict is empty, product is always visible
                $filteredProductCollection->addItem($product);
                continue;
            }

            $blacklist = substr($sinch_restrict, 0, 1) == "!";
            if($blacklist) {
                $sinch_restrict = substr($sinch_restrict, 1);
            }
            $product_account_groups = explode(",", $sinch_restrict);

            if((!$blacklist && in_array($account_group_id, $product_account_groups)) || //Whitelist and account group in list
                ($blacklist && !in_array($account_group_id, $product_account_groups))) { //Blacklist and account group not in list
                $filteredProductCollection->addItem($product);
            }
        }
    }
}
