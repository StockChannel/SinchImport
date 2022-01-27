<?php

namespace SITC\Sinchimport\Setup;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use SITC\Sinchimport\Plugin\Elasticsuite\InventoryData;
use Zend_Validate_Exception;

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
        /** @var EavSetup $eavSetup */
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

        if (version_compare($context->getVersion(), '2.3.1', '<')){
            $this->upgrade231($eavSetup);
        }

        if (version_compare($context->getVersion(), '2.4.0', '<')) {
            //Remove sinch_search_cache as it gains us nothing with ES
            $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
            $eavSetup->removeAttribute($entityTypeId, 'sinch_search_cache');
        }

        if (version_compare($context->getVersion(),'2.5.0', '<')) {
            $this->nileUpgrade($eavSetup);
        }

        if (version_compare($context->getVersion(),'2.5.2', '<')) {
            $this->nileUpgrade252($eavSetup);
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
//        $attrId = $eavSetup->getAttributeId('catalog_product', InventoryData::IN_STOCK_FILTER_CODE);
//        $options = [
//            'values' => [
//                'Y',
//                'N'
//            ],
//            'attribute_id' => $attrId
//        ];
//        $eavSetup->addAttributeOption($options);
    }

    /**
     * @throws Zend_Validate_Exception
     * @throws LocalizedException
     */
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
                'type' => 'text',
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
                'type' => 'text',
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
                'filterable_in_search' => true,
                'is_displayed_in_autocomplete' => true, //Enable Elasticsuite autocomplete suggestions
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
                'filterable_in_search' => true,
                'is_displayed_in_autocomplete' => true, //Enable Elasticsuite autocomplete suggestions
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
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
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

        //Product Videos
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_videos',
            [
                'label' => 'Product Videos',
                'note' => 'Semi-colon delimited Video URLs',
                'type' => 'text',
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

        //Product Manuals (basically the same as PDF Urls but HTML links)
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_manuals',
            [
                'label' => 'Product Manuals (HTML)',
                'note' => 'Semi-colon delimited Manual URLs',
                'type' => 'text',
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

        //Additional Images
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_additional_images',
            [
                'label' => 'Additional Image URLs',
                'note' => 'Semi-colon delimited Additional Image URLs',
                'type' => 'text',
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

        //Remove and re-add PDF URL as a text attribute (previously varchar)
        $eavSetup->removeAttribute(Product::ENTITY, 'pdf_url');
        $eavSetup->addAttribute(
            Product::ENTITY,
            'pdf_url',
            [
                'label' => 'PDF URLs',
                'note' => 'Semi-colon delimited PDF URLs',
                'type' => 'text',
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

        //Implied Sales (1m)
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_popularity_month',
            [
                'label' => 'Monthly Popularity',
                'note' => 'Stockinthechannel Implied Monthly Sales',
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
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Implied Sales (1y)
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_popularity_year',
            [
                'label' => 'Yearly Popularity',
                'note' => 'Stockinthechannel Implied Yearly Sales',
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
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'unique' => false,
                'group' => 'General'
            ]
        );

        //Virtual Category (category attribute)
        $eavSetup->addAttribute(
            Category::ENTITY,
            'sinch_virtual_category',
            [
                'label' => 'Virtual Category',
                'note' => 'Virtual category that products within this category can be also be categorized as',
                'type' => 'int',
                'input' => 'select',
                'backend' => '',
                'frontend' => '',
                'source' => '',
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'required' => false,
                'user_defined' => false,
                'searchable' => false,
                'filterable' => true,
                'comparable' => false,
                'visible_on_front' => false,
                'visible_in_advanced_search' => false,
                'unique' => false,
                'group' => 'General'
            ]
        );

        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        //Make specification not visible so Michael can make it its own product tab
        $eavSetup->updateAttribute($entityTypeId, 'specification', 'is_visible_on_front', 0);

        $eavSetup->updateAttribute($entityTypeId, 'manufacturer', 'is_filterable_in_search', 1);
        $eavSetup->updateAttribute($entityTypeId, 'manufacturer', 'is_displayed_in_autocomplete', 1);

        //Add the attributes for the list summary fields
        $summaryOpts = [
            'note' => 'Part of Stockinthechannel summary features',
            'type' => 'text',
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
        ];
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_summary_1',
            array_merge($summaryOpts, ['label' => 'Summary Feature 1'])
        );
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_summary_2',
            array_merge($summaryOpts, ['label' => 'Summary Feature 2'])
        );
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_summary_3',
            array_merge($summaryOpts, ['label' => 'Summary Feature 3'])
        );
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_summary_4',
            array_merge($summaryOpts, ['label' => 'Summary Feature 4'])
        );

        //Sinch searches
        $eavSetup->addAttribute(
            Product::ENTITY,
            'sinch_searches',
            [
                'label' => 'Searches',
                'note' => 'Number of searches for this product on Stockinthechannel',
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
                'is_visible_in_grid' => true,
                'is_filterable_in_grid' => true,
                'unique' => false,
                'group' => 'General'
            ]
        );
    }

    /**
     * Marks the BI data attributes as 'is_used_for_promo_rules' so they are indexed into ES
     * (meaning they do not have to be marked as 'searchable' which could have side effects)
     *
     * @param EavSetup $eavSetup
     * @throws LocalizedException
     */
    private function nileUpgrade252(EavSetup $eavSetup)
    {
        $entityTypeId = $eavSetup->getEntityTypeId(Product::ENTITY);
        $attrArr = ['sinch_score', 'sinch_popularity_month', 'sinch_popularity_year', 'sinch_searches'];
        foreach ($attrArr as $attr) {
            $eavSetup->updateAttribute($entityTypeId, $attr, 'is_used_for_promo_rules', 1);
        }
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resourceConn->getConnection(ResourceConnection::DEFAULT_CONNECTION);
    }
}