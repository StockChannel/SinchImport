<?php

namespace SITC\Sinchimport\Plugin\Catalog\Model\Category;

use Magento\Catalog\Model\Category\Image as MagentoImage;

class Image
{
    public function afterGetUrl(MagentoImage $_subject, $result, \Magento\Catalog\Model\Category $category, string $attributeCode = 'image'): string
    {
        $image = $category->getData($attributeCode);
        if (is_string($image) && str_starts_with($image, 'http')) {
            return $image;
        }
        return $result;
    }
}