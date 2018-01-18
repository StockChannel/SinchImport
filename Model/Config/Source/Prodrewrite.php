<?php

namespace SITC\Sinchimport\Model\Config\Source;

class Prodrewrite implements \Magento\Framework\Option\ArrayInterface
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