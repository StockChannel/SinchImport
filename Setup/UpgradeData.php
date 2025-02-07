<?php

namespace SITC\Sinchimport\Setup;

use Magento\Catalog\Api\Data\EavAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use SITC\Sinchimport\Plugin\Elasticsuite\InventoryData;

/**
 * @codeCoverageIgnore
 */
class UpgradeData implements UpgradeDataInterface
{
    /** @var \Magento\Eav\Setup\EavSetupFactory */
    private $eavSetupFactory;
    /** @var \Magento\Framework\App\ResourceConnection */
    private $resourceConn;
    /** @var \Magento\CatalogInventory\Api\StockConfigurationInterface */
    private $stockConfig;
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfig,
        ScopeConfigInterface $scopeConfig
    ){
        $this->eavSetupFactory = $eavSetupFactory;
        $this->resourceConn = $resourceConn;
        $this->stockConfig = $stockConfig;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

        if (version_compare($context->getVersion(), '2.1.1', '<' )) {
            //Make sinch_search_cache not visible on frontend
            $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
            $eavSetup->updateAttribute($entityTypeId, 'sinch_search_cache', 'is_visible_on_front', 0);
        }

        if (version_compare($context->getVersion(), '2.1.3', '<')){
            $this->fixStockManagement();
        }

        if (version_compare($context->getVersion(), '2.1.8', '<')){
            $this->upgrade218($eavSetup);
        }

        if (version_compare($context->getVersion(), '2.1.9', '<')){
            $this->upgrade219($eavSetup);
        }

        if (version_compare($context->getVersion(), '2.2.1', '<')){
            //Make sinch_restrict useable for promo rules (causing Elasticsuite to include it in the indexed documents)
            $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
            $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'is_used_for_promo_rules', 1);
        }

        if (version_compare($context->getVersion(), '2.3.1', '<')){
            $this->upgrade231($eavSetup);
        }

        if (version_compare($context->getVersion(), '2.3.2', '<')) {
            $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
            $eavSetup->updateAttribute($entityTypeId, 'sinch_in_stock', 'is_visible', 0);
        }
        if (version_compare($context->getVersion(), '2.3.3', '<')){
            $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
            $label = $this->scopeConfig->getValue('sinchimport/stock/stock_filter/stock_filter_label');
            $eavSetup->updateAttribute($entityTypeId, 'sinch_in_stock', EavAttributeInterface::FRONTEND_LABEL, $label);
        }

        $installer->endSetup();
    }

    private function fixStockManagement()
    {
        $conn = $this->getConnection();
        $catalogInvStockItem = $conn->getTableName('cataloginventory_stock_item');
        $stockItemWebsiteId = $this->stockConfig->getDefaultScopeId();

        $conn->query(
            "UPDATE {$catalogInvStockItem} SET website_id = :websiteId WHERE website_id != :websiteId",
            [":websiteId" => $stockItemWebsiteId]
        );
    }

    /**
     * Adds the UNSPSC and product restriction attributes
     * @var EavSetup $eavSetup
     */
    private function upgrade218($eavSetup)
    {
        //UNSPSC product attribute
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'unspsc',
            [
                'label' => 'UNSPSC',
                'type' => 'int',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'frontend_class' => 'validate-digits-range digits-range-0-99999999',
                'source' => '',
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'used_for_promo_rules' => true, //Allow use for promo rules
                'group' => 'General'
            ]
        );

        //Restrict products attribute
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'sinch_restrict',
            [
                'label' => 'Restrict Product to',
                'note' => 'Enter a comma separated list of Account IDs',
                'type' => 'varchar',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => true,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );
    }

    private function upgrade219($eavSetup)
    {
        $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
        $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'is_visible_on_front', 0);
        $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'used_in_product_listing', 1);
        $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'note', "Enter a comma separated list of Account Group IDs. An exclamation mark before the group ID negates the match");
    }


    private function upgrade231(EavSetup $eavSetup)
    {
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            InventoryData::IN_STOCK_FILTER_CODE,
            [
                'label' => 'In Stock',
                'type' => 'varchar',
                'input' => 'text',
                'frontend_class' => '',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => true,
                'comparable' => false,
                'visible_on_front' => true,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General',
                'facet_min_coverage_rate' => 10
            ]
        );
    }
    private function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }
}