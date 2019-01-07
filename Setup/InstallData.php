<?php

namespace SITC\Sinchimport\Setup;

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * @codeCoverageIgnore
 */
class InstallData implements InstallDataInterface
{
    /**
     * EAV setup factory
     *
     * @var EavSetupFactory
     */
    private $eavSetupFactory;
    
    /**
     * Init
     *
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(EavSetupFactory $eavSetupFactory)
    {
        $this->eavSetupFactory = $eavSetupFactory;
    }
    
    /**
     * {@inheritdoc}
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(
        ModuleDataSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        /**
         * @var EavSetup $eavSetup
         */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);
        
        // v 0.1.0 - 0.1.1
        $attrVarchar = [
            'ean' => 'EAN'
        ];
        
        foreach ($attrVarchar as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'varchar',
                    'input'                      => 'text',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => false,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => true,
                    'visible_in_advanced_search' => false,
                    'unique'                     => false
                ]
            );
        }
        
        $attrText = [
            'specification' => 'Specification'
        ];
        
        foreach ($attrText as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'text',
                    'input'                      => 'textarea',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => false,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => true,
                    'is_visible_on_front'        => true,
                    'is_html_allowed_on_front'   => true,
                    'visible_in_advanced_search' => false,
                    'unique'                     => false
                ]
            );
        }
        
        $attrText = [
            'specification' => 'Specification',
            'manufacturer'  => 'Manufacturer',
            'ean'           => 'EAN',
            'sku'           => 'SKU'
        ];
        
        foreach ($attrText as $key => $value) {
            $data = [
                'is_visible_on_front'      => 1,
                'is_html_allowed_on_front' => 1
            ];
            
            $entityTypeId = $eavSetup->getEntityTypeId(
                \Magento\Catalog\Model\Product::ENTITY
            );
            
            if ($id = $eavSetup->getAttribute(
                $entityTypeId,
                $key,
                'attribute_id'
            )
            ) {
                $eavSetup->updateAttribute($entityTypeId, $id, $data);
            }
        }
        
        $attr_filt = [
            'manufacturer' => 'Manufacturer'
        ];
        
        foreach ($attr_filt as $key => $value) {
            $data = [
                'is_filterable' => 1,
                'is_global'     => 1
            ];
            
            $entityTypeId = $eavSetup->getEntityTypeId(
                \Magento\Catalog\Model\Product::ENTITY
            );
            
            if ($id = $eavSetup->getAttribute(
                $entityTypeId,
                $key,
                'attribute_id'
            )
            ) {
                $eavSetup->updateAttribute($entityTypeId, $id, $data);
            }
            
            $sets = $setup->getConnection()->fetchAll(
                'select * from ' . $setup->getTable('eav_attribute_set')
                . ' where entity_type_id=?',
                $entityTypeId
            );
            
            foreach ($sets as $set) {
                $eavSetup->addAttributeToSet(
                    $entityTypeId,
                    $set['attribute_set_id'],
                    'Default',
                    'manufacturer'
                );
            }
        }
        
        $attrText = [
            'reviews' => 'Reviews'
        ];
        
        foreach ($attrText as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'text',
                    'input'                      => 'textarea',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => false,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => true,
                    'is_visible_on_front'        => 1,
                    'is_html_allowed_on_front'   => 1,
                    'visible_in_advanced_search' => false,
                    'unique'                     => false
                ]
            );
            
            $data = [
                'is_visible_on_front'      => 1,
                'is_html_allowed_on_front' => 1
            ];
            
            $entityTypeId = $eavSetup->getEntityTypeId(
                \Magento\Catalog\Model\Product::ENTITY
            );
            
            if ($id = $eavSetup->getAttribute(
                $entityTypeId,
                $key,
                'attribute_id'
            )
            ) {
                $eavSetup->updateAttribute($entityTypeId, $id, $data);
            }
        }
        
        $attrText = [
            'sinch_search_cache' => 'Sinch Search Cache'
        ];
        
        foreach ($attrText as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'text',
                    'input'                      => 'textarea',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => true,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => false,
                    'is_visible_on_front'        => 0,
                    'is_html_allowed_on_front'   => 1,
                    'visible_in_advanced_search' => true,
                    'unique'                     => false
                ]
            );
            
            $eavSetup->updateAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                'is_searchable',
                '1'
            );
        }
        
        $attrVarchar = [
            'pdf_url' => 'PDF Url'
        ];
        
        foreach ($attrVarchar as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'varchar',
                    'input'                      => 'text',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => false,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => true,
                    'visible_in_advanced_search' => false,
                    'unique'                     => false
                ]
            );
            
            $data = [
                'is_visible_on_front'      => 1,
                'is_html_allowed_on_front' => 1
            ];
            
            $entityTypeId = $eavSetup->getEntityTypeId(
                \Magento\Catalog\Model\Product::ENTITY
            );
            
            if ($id = $eavSetup->getAttribute(
                $entityTypeId,
                $key,
                'attribute_id'
            )
            ) {
                $eavSetup->updateAttribute($entityTypeId, $id, $data);
            }
        }
        
        $attrVarchar = [
            'supplier_1' => 'Supplier 1',
            'supplier_2' => 'Supplier 2',
            'supplier_3' => 'Supplier 3',
            'supplier_4' => 'Supplier 4',
            'supplier_5' => 'Supplier 5'
        ];
        
        foreach ($attrVarchar as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'varchar',
                    'input'                      => 'text',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => false,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => true,
                    'visible_in_advanced_search' => false,
                    'unique'                     => false
                ]
            );
            
            $data = [
                'is_visible_on_front'      => 0,
                'is_html_allowed_on_front' => 1
            ];
            
            $entityTypeId = $eavSetup->getEntityTypeId(
                \Magento\Catalog\Model\Product::ENTITY
            );
            
            if ($id = $eavSetup->getAttribute(
                $entityTypeId,
                $key,
                'attribute_id'
            )
            ) {
                $eavSetup->updateAttribute($entityTypeId, $id, $data);
            }
        }
        
        $attrVarchar = [
            'contract_id' => 'Contract ID',
        ];
        
        foreach ($attrVarchar as $key => $value) {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $key,
                [
                    'label'                      => $value,
                    'type'                       => 'varchar',
                    'input'                      => 'text',
                    'backend'                    => '',
                    'frontend'                   => '',
                    'source'                     => '',
                    'global'                     => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                    => true,
                    'required'                   => false,
                    'user_defined'               => false,
                    'searchable'                 => false,
                    'filterable'                 => false,
                    'comparable'                 => false,
                    'visible_on_front'           => true,
                    'visible_in_advanced_search' => false,
                    'unique'                     => false
                ]
            );
            
            $data = [
                'is_visible_on_front'      => 0,
                'is_html_allowed_on_front' => 1
            ];
            
            $entityTypeId = $eavSetup->getEntityTypeId(
                \Magento\Catalog\Model\Product::ENTITY
            );
            
            if ($id = $eavSetup->getAttribute(
                $entityTypeId,
                $key,
                'attribute_id'
            )
            ) {
                $eavSetup->updateAttribute($entityTypeId, $id, $data);
            }
        }
    }
}
