<?php

namespace SITC\Sinchimport\Plugin;

use Magento\Catalog\Helper\Product\ProductList;
use Magento\Framework\View\DesignInterface;

class InjectFeaturesBlock
{
    public function __construct(private readonly DesignInterface $design) {}

    /**
     * Intercepts on the Magento_Catalog's ListProduct block to ensure that the "keyfeatures" block that we
     * add in view/frontend/layout/catalog_category_view.xml is rendered to the html appropriately
     */
    public function afterGetProductDetailsHtml(\Magento\Catalog\Block\Product\ListProduct $subject, $result, \Magento\Catalog\Model\Product $product)
    {
        if (str_contains($this->design->getDesignTheme()->getCode(), "martfury")) {
            // Theme should be patched to include the block ala:
            // if ($keyFeatures = $block->getChildBlock('keyfeatures')) {
            //     echo $keyFeatures->setProduct($_product)->getChildHtml();
            // }
            // in martfury/layout01/Magento_Catalog/templates/product/list.phtml near the getProductDetailsHtml calls (grid and list)
            return $result;
        }

        // If the current mode is list, the keyfeatures block exists, render it and attach it to the end of product details
        /** @var \Magento\Catalog\Block\Product\ProductList\Item\Container $keyFeatures */
        if ($subject->getMode() == ProductList::VIEW_MODE_LIST && $keyFeatures = $subject->getChildBlock('keyfeatures')) {
            return $result . $keyFeatures->setProduct($product)->getChildHtml();
        }
        return $result;
    }
}