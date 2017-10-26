<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Plugin\Catalog\Helper;

class Image
{
    protected $currentProduct;
    
    /**
     * Initialize Helper to work with Image
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string                         $imageId
     * @param array                          $attributes
     *
     * @return $this
     */
    public function aroundInit(
        \Magento\Catalog\Helper\Image $subject,
        \Closure $proceed,
        $product,
        $imageId,
        $attributes = []
    ) {
        $this->currentProduct = $product;
        
        if ($product->getSinchProductId()) {
            $attributes = array_merge(
                $attributes, ['width' => 150, 'height' => 150]
            );
        }
        
        return $proceed($product, $imageId, $attributes);
    }
    
    /**
     * Return resized product image information
     *
     * @return array
     */
    public function aroundGetResizedImageInfo(
        \Magento\Catalog\Helper\Image $subject,
        \Closure $proceed
    ) {
        $imageSize = $proceed();
        
        if ($this->_getCurrentProduct()->getSinchProductId()) {
            $imageSize = $this->_getCurrentProduct()->getResizedImageInfo();
        }
        
        return $imageSize;
    }
    
    protected function _getCurrentProduct()
    {
        return $this->currentProduct;
    }
}
