<?php

namespace SITC\Sinchimport\Model\ResourceModel\Import\Mapping\RestrictedValueMapping;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
	protected $_idFieldName = 'sinch_id';
	protected $_eventPrefix = 'sitc_sinchimport_rvmapping_collection';
	protected $_eventObject = 'rvmapping_collection';

	/**
	 * Define resource model
	 *
	 * @return void
	 */
	protected function _construct()
	{
		$this->_init(
            'SITC\Sinchimport\Model\Import\Mapping\RestrictedValueMapping',
            'SITC\Sinchimport\Model\ResourceModel\Import\Mapping\RestrictedValueMapping'
        );
	}

}