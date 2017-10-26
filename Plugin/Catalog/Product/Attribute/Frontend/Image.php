<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Plugin\Catalog\Product\Attribute\Frontend;

class Image
{
    public function aroundGetUrl(\Magento\Catalog\Model\Product\Attribute\Frontend\Image $subject,
        \Closure $proceed, $product
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