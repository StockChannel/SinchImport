<?php

namespace SITC\Sinchimport\Model\ResourceModel\Import\Mapping;

class RestrictedValueMapping extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb {
    public function _construct()
    {
		$this->_init("sinch_restrictedvalue_mapping", "sinch_id");
	}
}