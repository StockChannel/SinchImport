<?php

namespace SITC\Sinchimport\Model\Config\Source;

class Subscription implements \Magento\Framework\Option\ArrayInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => '1', 'label' => '1 AM'],
            ['value' => '2', 'label' => '2 AM'],
            ['value' => '3', 'label' => '3 AM'],
            ['value' => '4', 'label' => '4 AM'],
            ['value' => '5', 'label' => '5 AM'],
            ['value' => '6', 'label' => '6 AM'],
            ['value' => '7', 'label' => '7 AM'],
            ['value' => '8', 'label' => '8 AM'],
            ['value' => '9', 'label' => '9 AM'],
            ['value' => '10', 'label' => '10 AM'],
            ['value' => '11', 'label' => '11 AM'],
            ['value' => '12', 'label' => '12 AM'],
            ['value' => '13', 'label' => '1 PM'],
            ['value' => '14', 'label' => '2 PM'],
            ['value' => '15', 'label' => '3 PM'],
            ['value' => '16', 'label' => '4 PM'],
            ['value' => '17', 'label' => '5 PM'],
            ['value' => '18', 'label' => '6 PM'],
            ['value' => '19', 'label' => '7 PM'],
            ['value' => '20', 'label' => '8 PM'],
            ['value' => '21', 'label' => '9 PM'],
            ['value' => '22', 'label' => '10 PM'],
            ['value' => '23', 'label' => '11 PM'],
            ['value' => '0', 'label' => '12 PM']
        ];
    }
    
    public function toArray()
    {
        return [
            '1'  => '1 AM',
            '2'  => '2 AM',
            '3'  => '3 AM',
            '4'  => '4 AM',
            '5'  => '5 AM',
            '6'  => '6 AM',
            '7'  => '7 AM',
            '8'  => '8 AM',
            '9'  => '9 AM',
            '10' => '10 AM',
            '11' => '11 AM',
            '12' => '12 AM',
            '13' => '1 PM',
            '14' => '2 PM',
            '15' => '3 PM',
            '16' => '4 PM',
            '17' => '5 PM',
            '18' => '6 PM',
            '19' => '7 PM',
            '20' => '8 PM',
            '21' => '9 PM',
            '22' => '10 PM',
            '23' => '11 PM',
            '0'  => '12 PM'
        ];
    }
}
