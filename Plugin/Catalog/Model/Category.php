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
        $result
    ) {
        $image = $subject->getImage();
        
        if (is_string($image) && substr($image, 0, 4) == 'http') {
            $url = $image;
        } else {
            $url = $result;
        }
        
        return $url;
    }
}
