<?php

namespace SITC\Sinchimport\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * {@inheritdoc}
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $installer = $setup;
        $installer->startSetup();
        
        // v0.1.0
        $installer->run(
            "DROP TABLE IF EXISTS {$installer->getTable('sinch_features_list')}"
        );
        
        $installer->run(
            "CREATE TABLE {$installer->getTable('sinch_features_list')}(
            `id` int  PRIMARY KEY NOT NULL AUTO_INCREMENT,
            `category_feature_id` int NOT NULL,
            `feature_id` int NULL,
            `feature_value` text
        )"
        );
        
        // v0.1.1 - 0.1.2
        $installer->run(
            "
            ALTER TABLE {$installer->getTable('catalog_product_entity')}
                ADD COLUMN `store_product_id` INT(11) UNSIGNED NULL
        "
        );
        
        $installer->run(
            "
            ALTER TABLE {$installer->getTable('catalog_product_entity')}
                ADD COLUMN `sinch_product_id` INT(11) UNSIGNED NULL
        "
        );
        
        $installer->run(
            "
            ALTER TABLE {$installer->getTable('catalog_category_entity')}
                ADD COLUMN `store_category_id` INT(11) UNSIGNED NULL
        "
        );
        
        $installer->run(
            "
            ALTER TABLE {$installer->getTable('catalog_category_entity')}
                ADD COLUMN `parent_store_category_id` INT(11) UNSIGNED NULL
        "
        );
        
        // v0.1.4 - 0.1.5
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable(
                'sinch_import_status_statistic'
            ) . ";
        "
        );
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable('sinch_import_status_statistic') . ";
        "
        );
        $installer->run(
            "
            CREATE TABLE " . $installer->getTable(
                'sinch_import_status_statistic'
            ) . "(
                id int(11) NOT NULL auto_increment PRIMARY KEY,
                start_import timestamp NOT NULL default '0000-00-00 00:00:00',
                finish_import timestamp NOT NULL default '0000-00-00 00:00:00',
                import_type varchar(50) default NULL,
                number_of_products int(11) default '0',
                global_status_import varchar(255) default NULL,
                detail_status_import varchar(255) default NULL,
                import_run_type varchar(255) default NULL,
                error_report_message  varchar(255) default NULL
            );
        "
        );
        
        // v0.1.7 - 0.1.8
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable('sinch_sinchcheck') . ";
        "
        );
        
        $installer->run(
            "
            CREATE TABLE " . $installer->getTable('sinch_sinchcheck') . "(
                id            INTEGER      NOT NULL AUTO_INCREMENT,
                caption       VARCHAR(100) NOT NULL DEFAULT 'caption',
                descr         VARCHAR(100) NOT NULL DEFAULT 'descr',
                check_code    VARCHAR(100)          DEFAULT NULL,
                check_value   VARCHAR(100)          DEFAULT 'check value',
                check_measure VARCHAR(100)          DEFAULT 'check measure',
                error_msg     VARCHAR(256)          DEFAULT 'error message',
                fix_msg       VARCHAR(256)          DEFAULT 'fix message',
                PRIMARY KEY (id),
                UNIQUE KEY uk_check_code(check_code)
            );
        "
        );
        
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('Physical memory', 'checking system memory', 'memory', '2048', 'MB',
                    'You have %s MB of memory', 'You need to enlarge memory to %s');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('Loaddata option', 'checking mysql load data', 'loaddata', 'ON', '',
                    'You need to enable the MySQL Loaddata option', 'You need to add set-variable=local-infile=1 or modify this line in /etc/my.cnf and restart MySQL');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('PHP safe mode', 'checking php safe mode', 'phpsafemode', 'OFF', '',
                    'You need to set PHP safe mode to %s', 'You need to set safe_mode = %s in /etc/php.ini');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('MySQL Timeout', 'checking mysql wait timeout', 'waittimeout', '28000', 'sec',
                    'Wait_timeout is too short:', 'You need to set wait_timeout = %s in /etc/my.cnf and restart MySQL');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('MySQL Buffer Pool', 'checking mysql innodb buffer pool size', 'innodbbufpool', '512', 'MB',
                    'The innodb_buffer_pool_size in /etc/my.cnf is %s', 'It needs to be set to %s or higher');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('PHP run string', 'checking php5 run string', 'php5run', 'php5', '',
                    'PHP_RUN_STRING uses value:', 'Change it to define(PHP_RUN_STRING, php5) in Bintime/Sinchimport/Model/config.php');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('File Permissions', 'checking chmod wget', 'chmodwget', '0755', '',
                    'You need to assign more rights to wget', 'Run chmod a+x wget');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('File Permissions', 'checking chmod for cron.php', 'chmodcronphp', '0755', '',
                    'You need to assign more rights to Magento Cron', 'Run chmod +x [shop dir]/cron.php');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('File Permissions', 'checking chmod for cron.sh', 'chmodcronsh', '0755', '',
                    'You need to assign more rights to Magento Cron', 'Run chmod +x [shop dir]/cron.sh');
        "
        );
        
        $installer->run(
            "
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code, check_value, check_measure,
                    error_msg, fix_msg)
                VALUE(
                    'Conflicts with installed plug-ins',
                    'checking conflicts with installed plug-ins and showing how to fix it',
                    'conflictwithinstalledmodules',
                    'Conflicts with installed plug-ins :',
                    '',
                    'Some of installed plug-ins rewrite Sinchimport module config',
                    'You can uninstall them or make inactive in [shop dir]/app/etc/modules ');
        "
        );
        
        // v0.1.9 - 0.2.0
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS " . $installer->getTable(
                'sinch_products_mapping'
            ) . "(
                entity_id int(11) unsigned NOT NULL,
                manufacturer_option_id int(11),
                manufacturer_name varchar(255),
                shop_store_product_id int(11),
                shop_sinch_product_id int(11),
                sku varchar(64) default NULL,
                store_product_id int(11),
                sinch_product_id int(11),
                product_sku varchar(255),
                sinch_manufacturer_id int(11),
                sinch_manufacturer_name varchar(255),
                KEY entity_id (entity_id),
                KEY manufacturer_option_id (manufacturer_option_id),
                KEY manufacturer_name (manufacturer_name),
                KEY store_product_id (store_product_id),
                KEY sinch_product_id (sinch_product_id),
                KEY sku (sku),
                UNIQUE KEY(entity_id)
            );
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS " . $installer->getTable(
                'sinch_products'
            ) . "(
                store_product_id int(11),
                sinch_product_id int(11),
                product_sku varchar(255),
                product_name varchar(255),
                sinch_manufacturer_id int(11),
                store_category_id int(11),
                main_image_url varchar(255),
                thumb_image_url varchar(255),
                specifications text,
                description text,
                search_cache text,
                spec_characte_u_count int(11),
                description_type varchar(50),
                medium_image_url varchar(255),
                products_date_added datetime default NULL,
                products_last_modified datetime default NULL,
                availability_id_in_stock int(11) default '1',
                availability_id_out_of_stock int(11) default '2',
                products_locate varchar(30) default NULL,
                products_ordered int(11) NOT NULL default '0',
                products_url varchar(255) default NULL,
                products_viewed int(5) default '0',
                products_seo_url varchar(100) NOT NULL,
                manufacturer_name varchar(255) default NULL,
                KEY(store_product_id),
                KEY(sinch_manufacturer_id),
                KEY(store_category_id)
            )DEFAULT CHARSET=utf8;
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS " . $installer->getTable(
                'sinch_categories_features'
            ) . "(
                category_feature_id int(11),
                store_category_id int(11),
                feature_name varchar(50),
                display_order_number int(11),
                KEY(store_category_id),
                KEY(category_feature_id)
            );
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS " . $installer->getTable(
                'sinch_restricted_values'
            ) . "(
                restricted_value_id int(11),
                category_feature_id int(11),
                text text,
                display_order_number int(11),
                KEY(restricted_value_id),
                KEY(category_feature_id)
            );
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS " . $installer->getTable(
                'sinch_product_features'
            ) . "(
                product_feature_id int(11),
                sinch_product_id int(11),
                restricted_value_id int(11),
                KEY(sinch_product_id),
                KEY(restricted_value_id)
            );
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS " . $installer->getTable(
                'sinch_categories_mapping'
            ) . "(
                shop_entity_id int(11) unsigned NOT NULL,
                shop_entity_type_id int(11),
                shop_attribute_set_id int(11),
                shop_parent_id int(11),
                shop_store_category_id int(11),
                shop_parent_store_category_id int(11),
                store_category_id int(11),
                parent_store_category_id int(11),
                category_name varchar(255),
                order_number int(11),
                products_within_this_category int(11),
                KEY shop_entity_id (shop_entity_id),
                KEY shop_parent_id (shop_parent_id),
                KEY store_category_id (store_category_id),
                KEY parent_store_category_id (parent_store_category_id),
                UNIQUE KEY(shop_entity_id)
            );
        "
        );
        
        // v3.0.1 - 3.0.2
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable('sinch_distributors')
            . ";
        "
        );
        
        $installer->run(
            "
            CREATE TABLE " . $installer->getTable('sinch_distributors') . "(
                distributor_id int(11),
                distributor_name varchar(255),
                website varchar(255),
                KEY(distributor_id)
            );
        "
        );
        
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable(
                'sinch_distributors_stock_and_price'
            ) . ";
        "
        );
        
        $installer->run(
            "
            CREATE TABLE " . $installer->getTable(
                'sinch_distributors_stock_and_price'
            ) . "(
                 `store_product_id` int(11) DEFAULT NULL,
                 `distributor_id` int(11) DEFAULT NULL,
                 `stock` int(11) DEFAULT NULL,
                 `cost` decimal(15,4) DEFAULT NULL,
                 `distributor_sku` varchar(255) DEFAULT NULL,
                 `distributor_category` varchar(50) DEFAULT NULL,
                 `eta` varchar(50) DEFAULT NULL,
                  UNIQUE KEY `product_distri` (store_product_id, distributor_id)
            );
        "
        );
        
        // v3.0.5 - 3.0.6
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable(
                'sinch_product_backup'
            ) . ";
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS {$installer->getTable('sinch_product_backup')} (
              `entity_id` int(11) unsigned NOT NULL,
              `sku` varchar(64) NULL,
              `store_product_id` int(10) unsigned NOT NULL,
              `sinch_product_id` int(11) unsigned NOT NULL,
              UNIQUE KEY (entity_id),
              KEY sku (sku),
              KEY store_product_id (store_product_id),
              KEY sinch_product_id (sinch_product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        "
        );
        
        $installer->run(
            "
            DROP TABLE IF EXISTS " . $installer->getTable(
                'sinch_category_backup'
            ) . ";
        "
        );
        
        $installer->run(
            "
            CREATE TABLE IF NOT EXISTS {$installer->getTable('sinch_category_backup')} (
              `entity_id` int(10) UNSIGNED NOT NULL,
              `entity_type_id` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
              `attribute_set_id` smallint(5) UNSIGNED NOT NULL DEFAULT '0',
              `parent_id` int(10) UNSIGNED NOT NULL DEFAULT '0',
              `store_category_id` int(11) UNSIGNED DEFAULT NULL,
              `parent_store_category_id` int(11) UNSIGNED DEFAULT NULL,
              UNIQUE KEY (entity_id),
              KEY entity_type_id (entity_type_id),
              KEY attribute_set_id (attribute_set_id),
              KEY parent_id (parent_id),
              KEY store_category_id (store_category_id),
              KEY parent_store_category_id (parent_store_category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

        "
        );

        $installer->endSetup();
    }
}
