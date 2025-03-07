<?php

namespace SITC\Sinchimport\Plugin\Catalog\Helper;

use Closure;
use Magento\Catalog\Model\Product;

class Image
{   
    /**
     * Initialize Helper to work with Image
     *
     * @param Product $product
     * @param string                         $imageId
     * @param array                          $attributes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @return                                        $this
     */
    public function aroundInit(
        \Magento\Catalog\Helper\Image $subject,
        Closure $proceed,
        $product,
        $imageId,
        $attributes = []
    ) {
        if ($product == null) {
            return $proceed($product, $imageId, $attributes);
        }
        
        if ($product->getSinchProductId()) {
            $attributes = array_merge(
                $attributes,
                ['width' => 150, 'height' => 150]
            );
        }

        return $proceed($product, $imageId, $attributes);
    }
}
