<?php
namespace SITC\Sinchimport\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SITC\Sinchimport\Helper\Data;

class ProductCollectionLoadAfter implements ObserverInterface
{
    private $helper;

    public function __construct(
        Data $helper
    ) {
        $this->helper = $helper;
    }


    public function execute(Observer $observer)
    {
        if(!$this->helper->isProductVisibilityEnabled() || $this->helper->isModuleEnabled('Smile_ElasticsuiteCatalog')){
            return; //No filtering if the feature isn't enabled or with Elasticsuite enabled (as thats handled by Plugin\Elasticsuite\ContainerConfiguration)
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();

        /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection $filteredProductCollection */
        $filteredProductCollection = $observer->getCollection();
        $productCollection = clone $filteredProductCollection;
        $filteredProductCollection->removeAllItems();


        /** @var \Magento\Catalog\Model\Product $product */
        foreach ($productCollection as $product) {
            if ($this->helper->checkProductVisibility($product, $account_group_id)) {
                $filteredProductCollection->addItem($product);
            }
        }
    }
}