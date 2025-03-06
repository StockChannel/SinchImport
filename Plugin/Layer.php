<?php
namespace SITC\Sinchimport\Plugin;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;
use SITC\Sinchimport\Helper\Data;

class Layer {
    /**
     * @var Data
     */
    private $helper;

    public function __construct(
        Data $helper
    ){
        $this->helper = $helper;
    }

    /**
     * Initialize product collection
     *
     * @param \Magento\Catalog\Model\Layer
     * @param Collection $collection
     * @return \Magento\Catalog\Model\Layer
     */
    public function beforePrepareProductCollection($subject, $collection)
    {
        $account_group_id = $this->helper->getCurrentAccountGroupId();
        if($account_group_id == false) {
            $account_group_id = 0;
        }

        if($this->helper->isProductVisibilityEnabled() && !$this->helper->isModuleEnabled('Smile_ElasticsuiteCatalog')){
            $collection->addAttributeToSelect('sinch_restrict', 'left');
            $collection->getSelect()->where(
                "(at_sinch_restrict.value IS NULL OR (LEFT(at_sinch_restrict.value, 1) != '!' AND FIND_IN_SET({$account_group_id}, at_sinch_restrict.value) >= 1) OR (LEFT(at_sinch_restrict.value, 1) = '!' AND FIND_IN_SET({$account_group_id}, SUBSTR(at_sinch_restrict.value,2)) = 0))",
                null,
                Select::TYPE_CONDITION
            );
        }

        return null;
    }
}
