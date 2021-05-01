<?php

namespace SITC\Sinchimport\Setup;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
    /** @var ResourceConnection */
    private $resourceConn;
    /** @var \Magento\CatalogInventory\Api\StockConfigurationInterface */
    private $stockConfig;

    public function __construct(
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        ResourceConnection $resourceConn,
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

        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

        if (version_compare($context->getVersion(), '2.1.1', '<' )) {
            //Make sinch_search_cache not visible on frontend
            $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
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
            $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
            $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'is_used_for_promo_rules', 1);
        }

        if (version_compare($context->getVersion(), '2.4.0', '<')) {
            //Remove sinch_search_cache as it gains us nothing with ES
            $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
            $eavSetup->removeAttribute($entityTypeId, 'sinch_search_cache');
        }

        if (version_compare($context->getVersion(),'2.5.0', '<')) {
            $this->nileUpgrade($eavSetup);
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
     *
     * @var EavSetup $eavSetup
     */
    private function upgrade218(EavSetup $eavSetup)
    {
        //UNSPSC product attribute
        $eavSetup->addAttribute(
            Product::ENTITY,
            'unspsc',
            [
                'label' => 'UNSPSC',
                'type' => 'int',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'frontend_class' => 'validate-digits-range digits-range-0-99999999',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
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
            Product::ENTITY,
            'sinch_restrict',
            [
                'label' => 'Restrict Product to',
                'note' => 'Enter a comma separated list of Account IDs',
                'type' => 'varchar',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
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

    private function upgrade219(EavSetup $eavSetup)
    {
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'is_visible_on_front', 0);
        $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'used_in_product_listing', 1);
        $eavSetup->updateAttribute($entityTypeId, 'sinch_restrict', 'note', "Enter a comma separated list of Account Group IDs. An exclamation mark before the group ID negates the match");
    }

    private function nileUpgrade(EavSetup $eavSetup)
    {
        //Add attributes for new features from the nile format

        //Bullet Points
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_bullet_points',
            [
                'label' => 'Bullet Points',
                'note' => 'Summary Bullet Points, expected to be triple pipe (|||) delimited',
                'type' => 'varchar',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Reasons to Buy
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_reasons_to_buy',
            [
                'label' => 'Reasons to Buy',
                'note' => 'Key Reasons to buy this product, expected to be triple pipe (|||) delimited',
                'type' => 'varchar',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Product Family
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_family',
            [
                'label' => 'Product Family',
                'type' => 'int',
                'input' => 'select', //Dropdown style
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => true,
                'filterable' => true,
                'comparable' => false,
                'visible_on_front' => true,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Product Family Series
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_family_series',
            [
                'label' => 'Product Series',
                'type' => 'int',
                'input' => 'select', //Dropdown style
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => true,
                'filterable' => true,
                'comparable' => false,
                'visible_on_front' => true,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Score
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_score',
            [
                'label' => 'Popularity Score',
                'type' => 'int',
                'input' => 'text',
                'backend' => '',
                'frontend' => '',
                'frontend_class' => 'validate-digits-range digits-range-0-99999999',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Release Date
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_release_date',
            [
                'label' => 'Release Date',
                'type' => 'datetime',
                'input' => 'datetime',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //EOL Date
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_eol_date',
            [
                'label' => 'End of Life Date',
                'type' => 'datetime',
                'input' => 'datetime',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resourceConn->getConnection(ResourceConnection::DEFAULT_CONNECTION);
    }
}