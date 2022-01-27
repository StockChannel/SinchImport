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

class ProductCollectionLoadAfter implements ObserverInterface
{
    private Data $helper;
    private Badges $badgeHelper;
    private Logger $logger;

    public function __construct(
        Data $helper,
        Badges $badgeHelper,
        Logger $logger
    ) {
        $this->helper = $helper;
        $this->badgeHelper = $badgeHelper;
        $this->logger = $logger;
    }


    public function execute(Observer $observer)
    {
        /** @var Collection $filteredProductCollection */
        $filteredProductCollection = $observer->getCollection();
        $productCollection = clone $filteredProductCollection;

        if ($this->helper->experimentalSearchEnabled() && $productCollection->getSize() > 4) {
            $badgeProducts = $this->badgeHelper->getProductsForBadges($productCollection);
            foreach ($badgeProducts as $value) {
                /** @var Product $product */
                $product = $value[0];
                $badgeType = $value[1];
                if (!empty($product->getData($badgeType)) && $product->getData($badgeType)) {
                    $product->unsetData($badgeType);
                }
                $this->badgeHelper->flagBadgeProduct($product, $badgeType);
            }
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
