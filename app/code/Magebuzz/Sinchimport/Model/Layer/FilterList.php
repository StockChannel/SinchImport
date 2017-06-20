<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model\Layer;

class FilterList
{
    /**
     * Retrieve list of filters
     *
     * @param \Magento\Catalog\Model\Layer $layer
     * @return array|Filter\AbstractFilter[]
     */
    public function beforeGetFilters(\Magento\Catalog\Model\Layer\FilterList $subject, $layer)
    {
        $subject->filters[] = $subject->objectManager->create('Magebuzz\Sinchimport\Model\Layer\Filter\Feature', ['layer' => $layer]);
        die(count($subject->filters));
    }
}
