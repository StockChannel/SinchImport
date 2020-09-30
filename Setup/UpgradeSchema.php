<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace SITC\Sinchimport\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{
    /**
     * {@inheritdoc}
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        if (version_compare($context->getVersion(), '2.1.1', '<')) {
            $connection = $installer->getConnection();
            $mappingTable = $installer->getTable('sinch_restrictedvalue_mapping');
            // Check if the table already exists
            if ($installer->getConnection()->isTableExists($mappingTable) != true) {
                $table = $installer->getConnection()
                    ->newTable($mappingTable)
                    ->addColumn(
                        'sinch_id',
                        Table::TYPE_INTEGER,
                        null,
                        [
                            'identity' => true,
                            'unsigned' => true,
                            'nullable' => false,
                            'primary' => true
                        ],
                        'Sinch Restricted Value ID'
                    )
                    ->addColumn(
                        'sinch_feature_id',
                        Table::TYPE_INTEGER,
                        null,
                        [
                            'unsigned' => true,
                            'nullable' => false,
                        ],
                        'Sinch Feature ID'
                    )
                    ->addColumn(
                        'option_id',
                        Table::TYPE_INTEGER,
                        null,
                        [
                            'unsigned' => true,
                            'nullable' => false,
                        ],
                        'Magento Option ID'
                    )
                    ->setComment('Sinch Restricted Value Mapping Table')
                    ->setOption('type', 'InnoDB')
                    ->setOption('charset', 'utf8');
                $installer->getConnection()->createTable($table);
            }
        }

        if (version_compare($context->getVersion(), '2.1.5', '<')) {
            $this->upgrade215($installer);
        }

        if (version_compare($context->getVersion(), '2.1.6', '<')) {
            $this->upgrade216($installer);
        }

        if (version_compare($context->getVersion(), '2.1.7', '<')) {
            $this->convertEnumToVarchar($setup);
            $this->createTableCustomerGroup($setup);
            $this->createTableCustomerGroupPrice($setup);
        }

        if (version_compare($context->getVersion(), '2.2.0', '<')) {
            $this->fixCustomerGroupPriceTable($setup);
        }

        if (version_compare($context->getVersion(), '2.2.2', '<')) {
            $mappingTable = $installer->getTable('sinch_restrictedvalue_mapping');
            //Add a foreign key between rvmapping and eaov so removed values are automatically dropped
            $installer->getConnection()->addForeignKey(
                $installer->getFkName($mappingTable, 'option_id', 'eav_attribute_option_value', 'option_id'),
                $mappingTable,
                'option_id',
                'eav_attribute_option_value',
                'option_id',
                $connection::FK_ACTION_CASCADE
            );
        }

        $installer->endSetup();
    }

    public function upgrade215($installer)
    {
        $connection = $installer->getConnection();
        $filterCategoryTable = $installer->getTable('sinch_filter_categories');
        if ($connection->isTableExists($filterCategoryTable) != true) {
            //Magento doesn't support composite primary keys, so just create the table manually (we won't be using it via Models anyway)
            $connection->query(
                "CREATE TABLE IF NOT EXISTS {$filterCategoryTable} (
                    feature_id INT NOT NULL COMMENT 'Sinch Feature ID',
                    category_id INT NOT NULL COMMENT 'Sinch Category ID',
                    PRIMARY KEY (feature_id, category_id),
                    INDEX(category_id)
                ) ENGINE=InnoDB"
            );
        }
    }

    private function upgrade216($installer)
    {
        $connection = $installer->getConnection();
        $catVisTable = $installer->getTable(\SITC\Sinchimport\Model\Import\CustomerGroupCategories::MAPPING_TABLE); //sinch_cat_visibility at the time of adding
        if ($connection->isTableExists($catVisTable) != true) {
            //Ditto of upgrade215
            $connection->query(
                "CREATE TABLE IF NOT EXISTS {$catVisTable} (
                    category_id INT NOT NULL COMMENT 'Category ID',
                    account_group_id INT NOT NULL COMMENT 'Account Group ID',
                    PRIMARY KEY (category_id, account_group_id),
                    INDEX(category_id)
                ) ENGINE=InnoDB"
            );
        }
        //Drop the sinch_filter_products procedure if it exists
        $sinch_filter_products = $installer->getTable('sinch_filter_products');
        $connection->query(
            "DROP PROCEDURE IF EXISTS {$sinch_filter_products}"
        );
        //Drop the sinch_calc_price function if it exists
        $connection->query(
            "DROP FUNCTION IF EXISTS sinch_calc_price"
        );
    }

    /**
     * @param SchemaSetupInterface $setup
     * @throws \Zend_Db_Exception
     */
    private function createTableCustomerGroup(SchemaSetupInterface $setup)
    {

        $connection = $setup->getConnection();
        $customerGroupTable = $connection->getTableName('sinch_customer_group');
        if (!$connection->isTableExists($customerGroupTable)) {
            $customerGroupTable = $setup->getConnection()
                ->newTable($setup->getTable('sinch_customer_group'))
                ->addColumn(
                    'group_id',
                    Table::TYPE_INTEGER,
                    11,
                    ['unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Group Id'
                )
                ->addColumn(
                    'group_name',
                    Table::TYPE_TEXT,
                    255,
                    ['unsigned' => true, 'nullable' => false],
                    'Group Name'
                )
                ->addIndex(
                    $setup->getIdxName(
                        'sinch_customer_group',
                        ['group_id', 'group_name'],
                        \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                    ),
                    ['group_id', 'group_name'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->setComment('Sinch Customer Group');
            $setup->getConnection()->createTable($customerGroupTable);
        }
    }

    /**
     * @param SchemaSetupInterface $setup
     * @throws \Zend_Db_Exception
     */
    private function createTableCustomerGroupPrice(SchemaSetupInterface $setup)
    {
        $connection = $setup->getConnection();
        $customerGroupTablePrice = $connection->getTableName('sinch_customer_group_price');

        if (!$connection->isTableExists($customerGroupTablePrice)) {
            $customerGroupTablePrice = $setup->getConnection()
                ->newTable($setup->getTable('sinch_customer_group_price'))
                ->addColumn(
                    'group_id',
                    Table::TYPE_INTEGER,
                    11,
                    ['unsigned' => true, 'nullable' => false],
                    'Group Id'
                )
                ->addColumn(
                    'product_id',
                    Table::TYPE_INTEGER, null, ['unsigned' => true, 'nullable' => false],
                    'Product Id'
                )
                ->addColumn(
                    'price_type_id',
                    Table::TYPE_INTEGER, 11, ['unsigned' => true, 'nullable' => false],
                    'Price Type Id'
                )
                ->addColumn(
                    'customer_group_price',
                    Table::TYPE_DECIMAL, '12,4', ['nullable' => false, 'default' => '0.0000'],
                    'Customer Group Price'
                )
                ->addColumn(
                    'sinch_product_id',
                    Table::TYPE_INTEGER, 11, ['unsigned' => true, 'nullable' => false],
                    'Sinch Product Id'
                )
                ->addIndex(
                    $setup->getIdxName(
                        'sinch_customer_group_price',
                        ['group_id', 'product_id', 'sinch_product_id', 'customer_group_price'],
                        \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                    ),
                    ['group_id', 'product_id','sinch_product_id', 'customer_group_price'],
                    ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->setComment('Sinch Customer Group Price');
            $setup->getConnection()->createTable($customerGroupTablePrice);
        }
    }

    private function convertEnumToVarchar(SchemaSetupInterface $setup)
    {
        $connection = $setup->getConnection();
        if ($connection->tableColumnExists('sinch_import_status_statistic', 'import_type')) {
            $connection->changeColumn(
                'sinch_import_status_statistic',
                'import_type',
                'import_type',
                [
                    'type' => Table::TYPE_TEXT,
                    'default' => null,
                    'length'  => '25',
                    'comment' => 'Import Type',
                    'after'   => 'finish_import'
                ]
            );
        }

        if ($connection->tableColumnExists('sinch_import_status_statistic', 'import_run_type')) {
            $connection->changeColumn(
                'sinch_import_status_statistic',
                'import_run_type',
                'import_run_type',
                [
                    'type' => Table::TYPE_TEXT,
                    'default' => null,
                    'length'  => '25',
                    'comment' => 'Import Run Type',
                    'after'   => 'detail_status_import'
                ]
            );
        }
    }

    private function fixCustomerGroupPriceTable(SchemaSetupInterface $setup) {
        $customerGroupPrice = $setup->getTable('sinch_customer_group_price');
        $setup->getConnection()->dropIndex(
            $customerGroupPrice,
            $setup->getIdxName(
                'sinch_customer_group_price',
                ['group_id', 'product_id', 'sinch_product_id', 'customer_group_price'],
                \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
            )
        );
        $setup->getConnection()->dropColumn($customerGroupPrice, 'sinch_product_id');
        $setup->getConnection()->addIndex(
            $customerGroupPrice,
            $setup->getIdxName(
                'sinch_customer_group_price',
                ['group_id', 'product_id', 'price_type_id'],
                \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
            ),
            ['group_id', 'product_id','price_type_id'],
            \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
        );
    }
}