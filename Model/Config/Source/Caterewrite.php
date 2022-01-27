<?php

namespace SITC\Sinchimport\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class Caterewrite implements ArrayInterface
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
