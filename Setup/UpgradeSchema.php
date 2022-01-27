<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace SITC\Sinchimport\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Model\Import\AccountGroupCategories;
use SITC\Sinchimport\Model\Import\StockPrice;
use Zend_Db_Exception;

/**
 * @codeCoverageIgnore
 */
class UpgradeSchema implements UpgradeSchemaInterface
{

	const SYNONYM_FILE = 'es_synonyms.csv';

	const THESAURUS_TABLE = 'smile_elasticsuite_thesaurus';
	const THESAURUS_STORE_TABLE = 'smile_elasticsuite_thesaurus_store';
	const THESAURUS_TERMS_TABLE = 'smile_elasticsuite_thesaurus_expanded_terms';

	/** @var Data */
	private $helper;

	public function __construct(Data $helper)
	{
		$this->helper = $helper;
	}

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
            $eavAttributeOptionValueTable = $installer->getTable('eav_attribute_option_value');
            //Add a foreign key between rvmapping and eaov so removed values are automatically dropped
            $connection = $installer->getConnection();
            $connection->addForeignKey(
                $installer->getFkName($mappingTable, 'option_id', $eavAttributeOptionValueTable, 'option_id'),
                $mappingTable,
                'option_id',
                $eavAttributeOptionValueTable,
                'option_id',
                $connection::FK_ACTION_CASCADE
            );
        }

        if (version_compare($context->getVersion(), '2.3.0', '<')) {
            $connection = $installer->getConnection();
            //Cleanup old unused tables
            $sdspt = $installer->getTable('sinch_distributors_stock_and_price_temporary');
            $sdspts = $installer->getTable('sinch_distributors_stock_and_price_temporary_supplier');
            $connection->query("DROP TABLE IF EXISTS {$sdspt}");
            $connection->query("DROP TABLE IF EXISTS {$sdspts}");
            $sinch_features_list = $installer->getTable('sinch_features_list');
            $connection->query("DROP TABLE IF EXISTS {$sinch_features_list}");

            //Now make sure the stock price import table has the layout we want
            $stockPriceImportTable = $installer->getTable(StockPrice::STOCK_IMPORT_TABLE);
            $connection->query("DROP TABLE IF EXISTS {$stockPriceImportTable}");
            $connection->query("CREATE TABLE IF NOT EXISTS {$stockPriceImportTable} (
                product_id int(11) NOT NULL PRIMARY KEY,
                stock int(11) NOT NULL,
                price decimal(15,4) NOT NULL,
                cost decimal(15,4),
                distributor_id int(11)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci");

            //Now make sure the distributor stock price import table has the layout we want
            $distiTable = $installer->getTable(StockPrice::DISTI_TABLE);
            $distiStockImportTable = $installer->getTable(StockPrice::DISTI_STOCK_IMPORT_TABLE);
            $connection->query("DROP TABLE IF EXISTS {$distiStockImportTable}");
            $connection->query("CREATE TABLE IF NOT EXISTS {$distiStockImportTable} (
                product_id int(11) NOT NULL,
                distributor_id int(11) NOT NULL,
                stock int(11) NOT NULL,
                PRIMARY KEY (distributor_id, product_id),
                FOREIGN KEY (distributor_id) REFERENCES {$distiTable} (distributor_id) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci");
        }

        if (version_compare($context->getVersion(), '2.4.0', '<')) {
            //Remove store_product_id (duplicate of sinch_product_id) on some tables
            $affectedTables = [
                'catalog_product_entity', //int(11) unsigned DEFAULT NULL
                'products_website_temp', //int(11) DEFAULT NULL
                'sinch_product_backup', //int(11) unsigned NOT NULL
                'sinch_products', //int(11) DEFAULT NULL
                'sinch_products_mapping', //int(11) DEFAULT NULL
                'sinch_products_pictures_gallery', //int(11) DEFAULT NULL
                'sinch_related_products' //int(11) DEFAULT NULL
            ];
            foreach ($affectedTables as $table) {
                $this->removeStoreProductId($installer, $table);
            }
        }

        if (version_compare($context->getVersion(), '2.5.0', '<')) {
            $connection = $installer->getConnection();

            //sinch_distributors - DROP website column
            $sinch_distributors = $installer->getTable('sinch_distributors');
            if ($installer->getConnection()->tableColumnExists($sinch_distributors, 'website')) {
                $connection->query("ALTER TABLE {$sinch_distributors} DROP COLUMN website");
            }

            //sinch_stock_and_prices - DROP distributor_id
            $sinch_stock_and_prices = $installer->getTable('sinch_stock_and_prices');
            if ($installer->getConnection()->tableColumnExists($sinch_stock_and_prices, 'distributor_id')) {
                $connection->query("ALTER TABLE {$sinch_stock_and_prices} DROP COLUMN distributor_id");
            }

            //sinch_customer_group_price_{cur,nxt} - DROP price_type
            $sinch_customer_group_price_cur = $installer->getTable('sinch_customer_group_price_cur');
            $sinch_customer_group_price_nxt = $installer->getTable('sinch_customer_group_price_nxt');
            //Alter the primary keys to not include price type
            $connection->query("ALTER TABLE {$sinch_customer_group_price_cur} DROP PRIMARY KEY, ADD PRIMARY KEY (sinch_group_id, sinch_product_id)");
            $connection->query("ALTER TABLE {$sinch_customer_group_price_nxt} DROP PRIMARY KEY, ADD PRIMARY KEY (sinch_group_id, sinch_product_id)");
            //Drop price_type
            if ($connection->tableColumnExists($sinch_customer_group_price_cur, 'price_type')) {
                $connection->query("ALTER TABLE {$sinch_customer_group_price_cur} DROP COLUMN price_type");
            }
            if ($connection->tableColumnExists($sinch_customer_group_price_nxt, 'price_type')) {
                $connection->query("ALTER TABLE {$sinch_customer_group_price_nxt} DROP COLUMN price_type");
            }
            //Add Synonyms
            $this->insertSynonyms($installer);
        }

        if (version_compare($context->getVersion(), '2.5.1', '<')) {
            $connection = $installer->getConnection();
            $sinch_import_status = $installer->getTable('sinch_import_status');

            $connection->query("DROP TABLE $sinch_import_status");
            $connection->query("CREATE TABLE IF NOT EXISTS $sinch_import_status (
                id int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message varchar(255) NOT NULL UNIQUE,
                finished tinyint(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci");
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
            );
        }
    }

    private function upgrade216($installer)
    {
        $connection = $installer->getConnection();
        $catVisTable = $installer->getTable(AccountGroupCategories::MAPPING_TABLE); //sinch_cat_visibility at the time of adding
        if ($connection->isTableExists($catVisTable) != true) {
            //Ditto of upgrade215
            $connection->query(
                "CREATE TABLE IF NOT EXISTS {$catVisTable} (
                    category_id INT NOT NULL COMMENT 'Category ID',
                    account_group_id INT NOT NULL COMMENT 'Account Group ID',
                    PRIMARY KEY (category_id, account_group_id),
                    INDEX(category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
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
     * @throws Zend_Db_Exception
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
                        AdapterInterface::INDEX_TYPE_UNIQUE
                    ),
                    ['group_id', 'group_name'],
                    ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
                )
                ->setComment('Sinch Customer Group');
            $setup->getConnection()->createTable($customerGroupTable);
        }
    }

    /**
     * @param SchemaSetupInterface $setup
     * @throws Zend_Db_Exception
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
                        AdapterInterface::INDEX_TYPE_UNIQUE
                    ),
                    ['group_id', 'product_id','sinch_product_id', 'customer_group_price'],
                    ['type' => AdapterInterface::INDEX_TYPE_UNIQUE]
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
                AdapterInterface::INDEX_TYPE_UNIQUE
            )
        );
        $setup->getConnection()->dropColumn($customerGroupPrice, 'sinch_product_id');
        $setup->getConnection()->addIndex(
            $customerGroupPrice,
            $setup->getIdxName(
                'sinch_customer_group_price',
                ['group_id', 'product_id', 'price_type_id'],
                AdapterInterface::INDEX_TYPE_UNIQUE
            ),
            ['group_id', 'product_id','price_type_id'],
            AdapterInterface::INDEX_TYPE_UNIQUE
        );
    }

    private function removeStoreProductId(SchemaSetupInterface $setup, string $table) {
        $conn = $setup->getConnection();
        $actualTable = $conn->getTableName($table);
        //Check that both store_product_id and sinch_product_id exist as columns (otherwise what we want to do wont work)
        if ($conn->tableColumnExists($actualTable, 'store_product_id') && $conn->tableColumnExists($actualTable, 'sinch_product_id')) {
            //Drop the sinch_product_id column (as its specified second in important tables like catalog_product_entity)
            // and then rename store_product_id to sinch_product_id (its a better name)
            $conn->dropColumn($actualTable, 'sinch_product_id');
            $conn->changeColumn(
                $actualTable,
                'store_product_id',
                'sinch_product_id',
                [
                    'unsigned' => true,
                    'default' => null,
                    'type' => Table::TYPE_INTEGER,
                    'scale' => 11,
                    'nullable' => true,
                    'comment' => 'Sinch Product Id'
                ],
                false
            );
        }
    }

    private function insertSynonyms(SchemaSetupInterface $setup)
    {
		$conn = $setup->getConnection();
		$thesaurusTable = $conn->getTableName(self::THESAURUS_TABLE);
		$thesaurusStoreTable = $conn->getTableName(self::THESAURUS_STORE_TABLE);
		$thesaurusTermsTable = $conn->getTableName(self::THESAURUS_TERMS_TABLE);

		$sinchThesaurusExists = true;
		if (empty($conn->fetchAll("SELECT thesaurus_id FROM {$thesaurusTable} WHERE name = 'Sinch'"))) {
			$conn->query("INSERT INTO {$thesaurusTable} (name, type, is_active) VALUES ('Sinch', 'synonym', 1)");
			$sinchThesaurusExists = false;
		}

	    $thesaurusId = $conn->fetchOne("SELECT thesaurus_id FROM {$thesaurusTable} WHERE name = 'Sinch'");

		if (empty($thesaurusId)) {
			return;
		} else {
			$thesaurusId = (int)$thesaurusId; //Convert to int for insert to db
		}

		if (!$sinchThesaurusExists)
			$conn->query("INSERT INTO {$thesaurusStoreTable} VALUES ({$thesaurusId}, 0)");

		//Load the synonym CSV into an array
	    $filePath = $this->helper->getModuleDirectory(Dir::MODULE_ETC_DIR) . '/' . self::SYNONYM_FILE;
	    $csvLines = array_map('str_getcsv', file($filePath));

	    $row = 1;
	    foreach ($csvLines as $line) {
	    	foreach ($line as $synonym) {
			    $conn->query("INSERT IGNORE INTO {$thesaurusTermsTable} VALUES (:id, :rowId, :term)",
				    ['id' => $thesaurusId, 'rowId' => $row, 'term' => $synonym]);
		    }
	    	$row++;
	    }
    }
}
