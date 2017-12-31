<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model\Config\Source;

class AdditionalSuffix implements \Magento\Framework\Option\ArrayInterface
{
    const ADDITIONAL_SUFFIX_CONFIG_NONE = 0;
    const ADDITIONAL_SUFFIX_CONFIG_PRODUCT_ID = 1;
    const ADDITIONAL_SUFFIX_CONFIG_PRODUCT_SKU = 2;

    public function toOptionArray()
    {
        return [
            ['value' => self::ADDITIONAL_SUFFIX_CONFIG_NONE, 'label' => 'None'],
            ['value' => self::ADDITIONAL_SUFFIX_CONFIG_PRODUCT_ID,
             'label' => 'Product ID'],
            ['value' => self::ADDITIONAL_SUFFIX_CONFIG_PRODUCT_SKU,
             'label' => 'Product SKU']
        ];
    }

    public function toArray()
    {
        return [
            self::ADDITIONAL_SUFFIX_CONFIG_NONE        => 'None',
            self::ADDITIONAL_SUFFIX_CONFIG_PRODUCT_ID  => 'Product ID',
            self::ADDITIONAL_SUFFIX_CONFIG_PRODUCT_SKU => 'Product SKU'
        ];
    }
}
