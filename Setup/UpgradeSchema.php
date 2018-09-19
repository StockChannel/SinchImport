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
        if (version_compare($context->getVersion(), '2.1.1', '<')) {
            $installer = $setup;

            $installer->startSetup();

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

            $installer->endSetup();
        }
    }
}