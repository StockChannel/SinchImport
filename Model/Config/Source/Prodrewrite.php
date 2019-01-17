<?php

namespace SITC\Sinchimport\Model\Config\Source;

/**
 * Class Prodrewrite
 * @package SITC\Sinchimport\Model\Config\Source
 */
class Prodrewrite implements \Magento\Framework\Option\ArrayInterface
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
