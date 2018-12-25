<?php

namespace SITC\Sinchimport\Plugin\Catalog\Product\Attribute\Frontend;

/**
 * Class Image
 * @package SITC\Sinchimport\Plugin\Catalog\Product\Attribute\Frontend
 */
class Image
{
    /**
     * @param \Magento\Catalog\Model\Product\Attribute\Frontend\Image $subject
     * @param \Closure $proceed
     * @param $product
     * @return mixed
     */
    public function aroundGetUrl(
        \Magento\Catalog\Model\Product\Attribute\Frontend\Image $subject,
        \Closure $proceed,
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