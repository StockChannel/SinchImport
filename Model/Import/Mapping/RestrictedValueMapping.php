<?php

namespace SITC\Sinchimport\Model\Import\Mapping;

class RestrictedValueMapping extends \Magento\Framework\Model\AbstractModel implements \Magento\Framework\DataObject\IdentityInterface
{
    
    const CACHE_TAG = 'sitc_sinchimport_rvmapping';

    public function _construct()
    {
		$this->_init('SITC\Sinchimport\Model\ResourceModel\Import\Mapping\RestrictedValueMapping');
    }

    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }
}