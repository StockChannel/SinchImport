<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Plugin\Catalog\Model;

class Category
{
    /**
     * Retrieve image URL
     *
     * @return string
     */
    public function afterGetImageUrl(\Magento\Catalog\Model\Category $subject,
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
