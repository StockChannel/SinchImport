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
            $this->upgrade216($installer);
            $this->changeColumnStatus($installer);
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

    public function upgrade216($installer)
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
     */
    public function changeColumnStatus(SchemaSetupInterface $setup)
    {
        $connection = $setup->getConnection();
        if ($connection->tableColumnExists(
            'sinch_import_status_statistic', 'import_type')
        ) {
            $connection->changeColumn(
                'sinch_import_status_statistic',
                'import_type',
                'import_type',
                [
                    'type' => Table::TYPE_TEXT,
                    'default' => null,
                    'length'  => '50',
                    'comment' => 'Import Type',
                    'after'   => 'finish_import'
                ]
            );
        }
    }
}