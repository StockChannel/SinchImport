<?php
namespace SITC\Sinchimport\Plugin;
class LayerPluginTest {
    /**
     * @var \SITC\Sinchimport\Helper\Data
     */
    private $helper;
    private $registry;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \Magento\Framework\Registry $registry
    ){
        $this->helper = $helper;
        $this->registry = $registry;
    }

    /**
     * Initialize product collection
     *
     * @param \Magento\Catalog\Model\Layer
     * @param \Magento\Catalog\Model\ResourceModel\Product\Collection $collection
     * @return \Magento\Catalog\Model\Layer
     */
    public function beforePrepareProductCollection($subject, $collection)
    {
        $account_group_id = $this->helper->getCurrentAccountGroupId(); //$this->registry->registry('sitc_account_group_id');
        if($account_group_id == false) {
            $account_group_id = 0;
        }

        // $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/test.log');
        // $logger = new \Zend\Log\Logger();
        // $logger->addWriter($writer);

        $collection->addAttributeToSelect('sinch_restrict', 'left');
        if(!$this->helper->isModuleEnabled('Smile_ElasticsuiteCatalog')){
            $collection->getSelect()->where(
                "(at_sinch_restrict.value IS NULL OR (LEFT(at_sinch_restrict.value, 1) != '!' AND FIND_IN_SET({$account_group_id}, at_sinch_restrict.value) >= 1) OR (LEFT(at_sinch_restrict.value, 1) = '!' AND FIND_IN_SET({$account_group_id}, SUBSTR(at_sinch_restrict.value,2)) = 0))",
                null,
                \Magento\Framework\DB\Select::TYPE_CONDITION
            );
        }

        // $logger->info("Layer plugin: " . $collection->getSelect());
        //$subject->getCurrentCategory();
    }
}