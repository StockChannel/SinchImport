<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model\Config\Source;

class Caterewrite implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'REWRITE', 'label' => 'Overwrite'],
            ['value' => 'MERGE', 'label' => 'Merge']
        ];
    }

    public function toArray()
    {
        return [
            'REWRITE' => 'Overwrite',
            'MERGE'   => 'Merge'
        ];
    }
}
