<?php

namespace SITC\Sinchimport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class RelationType implements OptionSourceInterface
{

    public function toOptionArray()
    {
        return [
            ['label' => 'Upsell', 'value' => 'up_sell'],
            ['label' => 'Cross-sell', 'value' => 'cross_sell'],
            ['label' => 'Related', 'value' => 'relation'],
            ['label' => 'None', 'value' => 'unused']
        ];
    }
}