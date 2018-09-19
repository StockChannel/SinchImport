<?php

namespace SITC\Sinchimport\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * @codeCoverageIgnore
 */
class UpgradeData implements UpgradeDataInterface
{
    /** @var \Magento\Eav\Setup\EavSetupFactory */
    private $eavSetupFactory;

    public function __construct(\Magento\Eav\Setup\EavSetupFactory $eavSetupFactory)
    {
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
 
        if (version_compare($context->getVersion(), '2.1.1', '<' )) {
            $installer->startSetup();

            //Make sinch_search_cache not visible on frontend
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
            $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
            $eavSetup->updateAttribute($entityTypeId, 'sinch_search_cache', 'is_visible_on_front', 0);

            $installer->endSetup();
        }
    }
}