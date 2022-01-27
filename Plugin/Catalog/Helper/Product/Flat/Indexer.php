<?php

namespace SITC\Sinchimport\Plugin\Catalog\Helper\Product\Flat;

use Closure;

class Indexer
{
    protected $_columns;

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetFlatColumns(\Magento\Catalog\Helper\Product\Flat\Indexer $subject, Closure $proceed)
    {
        $this->_columns = $proceed();

        $this->_columns['sinch_product_id'] = [
            'unsigned' => true,
            'default' => null,
            'extra' => null,
            'type' => 'integer',
            'length' => 11,
            'nullable' => false,
            'comment' => 'Sinch Product Id'
        ];

        return $this->_columns;
    }
}
