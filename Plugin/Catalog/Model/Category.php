<?php

namespace SITC\Sinchimport\Plugin\Catalog\Model;

class Category
{
    /**
     * Retrieve image URL
     *
     * @return string
     */
    public function afterGetImageUrl(
        \Magento\Catalog\Model\Category $subject,
        $result,
        $attributeCode = 'image'
    ) {
        $image = $subject->getData($attributeCode);
        
        if (is_string($image) && str_starts_with($image, 'http')) {
            $url = $image;
        } else {
            $url = $result;
        }
        
        return $url;
    }
}
