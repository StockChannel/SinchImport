<?php

namespace SITC\Sinchimport\Model\Config\Source;

/**
 * Class Caterewrite
 * @package SITC\Sinchimport\Model\Config\Source
 */
class Caterewrite implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'REWRITE', 'label' => 'Overwrite'],
            ['value' => 'MERGE', 'label' => 'Merge']
        ];
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'REWRITE' => 'Overwrite',
            'MERGE'   => 'Merge'
        ];
    }
}
