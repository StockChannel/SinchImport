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
    /**
     * @var \Magento\Eav\Setup\EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConn;

    /**
     * @var \Magento\CatalogInventory\Api\StockConfigurationInterface
     */
    private $stockConfig;

    /**
     * UpgradeData constructor.
     * @param \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory
     * @param \Magento\Framework\App\ResourceConnection $resourceConn
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfig
     */
    public function __construct(
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfig
    ){
        $this->eavSetupFactory = $eavSetupFactory;
        $this->resourceConn = $resourceConn;
        $this->stockConfig = $stockConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
 
        if (version_compare($context->getVersion(), '2.1.1', '<' )) {
            //Make sinch_search_cache not visible on frontend
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
            $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
            $eavSetup->updateAttribute($entityTypeId, 'sinch_search_cache', 'is_visible_on_front', 0);
        }

        if (version_compare($context->getVersion(), '2.1.3', '<')){
            $this->fixStockManagement();
        }

        $installer->endSetup();
    }

    /**
     *
     */
    private function fixStockManagement()
    {
        $conn = $this->getConnection();
        $catalogInvStockItem = $this->resourceConn->getTableName('cataloginventory_stock_item');
        $stockItemWebsiteId = $this->stockConfig->getDefaultScopeId();

        $conn->query(
            "UPDATE {$catalogInvStockItem} SET website_id = :websiteId WHERE website_id != :websiteId",
            [":websiteId" => $stockItemWebsiteId]
        );
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }
}