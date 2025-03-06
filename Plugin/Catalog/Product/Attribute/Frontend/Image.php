<?php

namespace SITC\Sinchimport\Plugin\Catalog\Product\Attribute\Frontend;

use Closure;

class Image
{
    public function aroundGetUrl(
        \Magento\Catalog\Model\Product\Attribute\Frontend\Image $subject,
        Closure $proceed,
        $product
    ) {
        $image = $product->getData(
            $subject->getAttribute()->getAttributeCode()
        );
        if (substr($image, 0, 4) === 'http') {
            return $image;
        } else {
            return $proceed($product);
        }
    }
}
