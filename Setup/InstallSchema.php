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
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(SchemaSetupInterface $setup,
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
            CREATE TABLE " . $installer->getTable(
                'sinch_import_status_statistic'
            ) . "(
                id int(11) NOT NULL auto_increment PRIMARY KEY,
                start_import timestamp NOT NULL default '0000-00-00 00:00:00',
                finish_import timestamp NOT NULL default '0000-00-00 00:00:00',
                import_type ENUM('FULL', 'PRICE STOCK') default NULL,
                number_of_products int(11) default '0',
                global_status_import varchar(255) default '',
                detail_status_import varchar(255) default '',
                import_run_type ENUM ('MANUAL', 'CRON') default NULL,
                error_report_message  text not null default ''
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
            INSERT " . $installer->getTable('sinch_sinchcheck') . "(caption, descr, check_code,
                    check_value, check_measure,
                    error_msg, fix_msg)
                VALUE('Missing Procedure', 'checking absense of procedure "
            . $installer->getTable('sinch_filter_products') . ".sql store procedue and showing hot to add it', 'routine',
                    '" . $installer->getTable('sinch_filter_products') . "', '',
                    'You are missing the MySQL stored procedure "
            . $installer->getTable('sinch_filter_products') . ".sql', 'You can recreate it by running the script found in [shop dir]/app/code/local/Bintime/Sinchimport/sql/ in PhpMyAdmin');
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
        
        // Create procedure to filter product features and calculating price function
        $installer->run(
            "DROP PROCEDURE IF EXISTS " . $installer->getTable(
                'sinch_filter_products'
            )
        );
        $installer->run(
            "DROP FUNCTION IF EXISTS " . $installer->getTable(
                'sinch_calc_price'
            )
        );
        
        $config = $installer->getConnection()->getConfig();
        $connection = mysqli_connect(
            $config['host'], $config['username'], $config['password']
        );
        
        if ( ! $connection) {
            throw new \Exception('Failed to connect to database.');
        }
        
        if ( ! mysqli_select_db($connection, $config['dbname'])) {
            throw new \Exception('Failed to select a database.');
        }
        
        $createProcedureQuery
            = "
CREATE PROCEDURE " . $installer->getTable('sinch_filter_products') . "(
    IN arg_table INT,
    IN arg_category_id INT,
    IN arg_image INT,
    IN arg_category_feature INT,
    IN arg_least INT,
    IN arg_greatest INT,
    IN arg_table_prefix VARCHAR(255)
)
BEGIN
    DROP TABLE IF EXISTS `tmp_result`;

    CREATE TEMPORARY TABLE `tmp_result`(
        `entity_id` int(10) unsigned,
        `category_id` int(10) unsigned,
        `product_id` int,
        `sinch_category_id` int,
        `name` varchar(255),
        `image` varchar(255),
        `supplier_id` int,
        `category_feature_id` int,
        `feature_id` int,
        `feature_name` varchar(255),
        `feature_value` varchar(255)
    );


    IF arg_image = 1 THEN
    SET @updquery = CONCAT('

        INSERT INTO `tmp_result` (
            entity_id,
            category_id,
            product_id,
            sinch_category_id,
            name,
            image,
            supplier_id,
            category_feature_id,
            feature_id,
            feature_name,
            feature_value
        )(
          SELECT
            E.entity_id,
            PCind.category_id,
            E.entity_id,
            PCind.category_id as sinch_category,
            PR.product_name,
            PR.main_image_url,
            PR.sinch_manufacturer_id,
            CF.category_feature_id,
            CF.category_feature_id,
            CF.feature_name,
            RV.text
          FROM ', arg_table_prefix, 'catalog_product_entity E
          INNER JOIN ', arg_table_prefix, 'catalog_category_product_index PCind
            ON (E.entity_id = PCind.product_id)
          INNER JOIN ', arg_table_prefix, 'sinch_categories_mapping scm
            ON PCind.category_id=scm.shop_entity_id
          INNER JOIN ',arg_table_prefix, 'sinch_categories_features CF
            ON (scm.store_category_id=CF.store_category_id)
          INNER JOIN ',arg_table_prefix, 'sinch_products PR
            ON (PR.store_product_id = E.store_product_id)
          INNER JOIN ',arg_table_prefix, 'sinch_product_features PF
            ON (PR.sinch_product_id = PF.sinch_product_id )
          INNER JOIN ',arg_table_prefix, 'sinch_restricted_values RV
            ON (PF.restricted_value_id=RV.restricted_value_id)
          WHERE
            scm.shop_entity_id = ', arg_category_id, '
            AND PR.main_image_url <> \'\'
          GROUP BY E.entity_id, CF.category_feature_id, CF.feature_name, RV.text
        )
    ');
    ELSE
    SET @updquery = CONCAT('

        INSERT INTO `tmp_result` (
            entity_id,
            category_id,
            product_id,
            sinch_category_id,
            name,
            image,
            supplier_id,
            category_feature_id,
            feature_id,
            feature_name,
            feature_value
        )(
          SELECT
            E.entity_id,
            PCind.category_id,
            E.entity_id,
            PCind.category_id as sinch_category,
            PR.product_name,
            PR.main_image_url,
            PR.sinch_manufacturer_id,
            CF.category_feature_id,
            CF.category_feature_id,
            CF.feature_name,
            RV.text
          FROM ', arg_table_prefix ,'catalog_product_entity E
          INNER JOIN ', arg_table_prefix, 'catalog_category_product_index PCind
            ON (E.entity_id = PCind.product_id)
          INNER JOIN ', arg_table_prefix, 'sinch_categories_mapping scm
            ON PCind.category_id=scm.shop_entity_id
          INNER JOIN ', arg_table_prefix, 'sinch_categories_features CF
            ON (scm.store_category_id=CF.store_category_id)
          INNER JOIN ', arg_table_prefix, 'sinch_products PR
            ON (PR.store_product_id = E.store_product_id)
          INNER JOIN ', arg_table_prefix, 'sinch_product_features PF
            ON (PR.sinch_product_id = PF.sinch_product_id )
          INNER JOIN ', arg_table_prefix, 'sinch_restricted_values RV
            ON (PF.restricted_value_id=RV.restricted_value_id)
          WHERE
            scm.shop_entity_id = ', arg_category_id, '
          GROUP BY E.entity_id, CF.category_feature_id, CF.feature_name, RV.text
        )
    ');
    END IF;

    PREPARE myquery FROM @updquery;
    EXECUTE myquery;
    DROP PREPARE myquery;

    IF (arg_least IS null AND arg_greatest IS null) THEN
        SET @query = CONCAT('
            INSERT INTO `', arg_table_prefix, 'sinch_filter_result_', arg_table, '` (
                entity_id,
                category_id,
                product_id,
                sinch_category_id,
                name,
                image,
                supplier_id,
                category_feature_id,
                feature_id,
                feature_name,
                feature_value
            )(
                SELECT
                    TR.entity_id,
                    TR.category_id,
                    TR.product_id,
                    TR.sinch_category_id,
                    TR.name,
                    TR.image,
                    TR.supplier_id,
                    TR.category_feature_id,
                    TR.feature_id,
                    TR.feature_name,
                    TR.feature_value
                FROM `tmp_result` AS TR
                WHERE TR.category_feature_id = \'', arg_category_feature, '\'
            )
            ON DUPLICATE KEY UPDATE feature_value = TR.feature_value
        ');
    ELSE
        IF (arg_least IS NOT null AND arg_greatest IS NOT null) THEN
            SET @where = CONCAT(' AND TR.feature_value >= ', arg_least, ' AND TR.feature_value <', arg_greatest, ' ');
        ELSE
            IF arg_least IS null THEN
                SET @where = CONCAT(' AND TR.feature_value < ', arg_greatest, ' ');
            ELSE
                SET @where = CONCAT(' AND TR.feature_value >= ', arg_least, ' ');
            END IF;
        END IF;

        SET @query = CONCAT('
            INSERT INTO `', arg_table_prefix, 'sinch_filter_result_', arg_table, '` (
                entity_id,
                category_id,
                product_id,
                sinch_category_id,
                name,
                image,
                supplier_id,
                category_feature_id,
                feature_id,
                feature_name,
                feature_value
            )(
                SELECT
                    TR.entity_id,
                    TR.category_id,
                    TR.product_id,
                    TR.sinch_category_id,
                    TR.name,
                    TR.image,
                    TR.supplier_id,
                    TR.category_feature_id,
                    TR.feature_id,
                    TR.feature_name,
                    TR.feature_value
                FROM `tmp_result` AS TR
                WHERE TR.category_feature_id = \'', arg_category_feature, '\'',
                @where,'
            )
            ON DUPLICATE KEY UPDATE feature_value = TR.feature_value
        ');

    END IF;

    PREPARE myquery FROM @query;
    EXECUTE myquery;
    DROP PREPARE myquery;
END
        ";
        
        if ( ! mysqli_query($connection, $createProcedureQuery)) {
            throw new \Exception("Failed to create stored procedure");
        }
        
        $createCalPriceFunctionQuery
            = "
CREATE FUNCTION " . $installer->getTable('sinch_calc_price') . " (price decimal(8,2) , marge decimal(10,2), fixed decimal(10,2), final_price decimal(10,2)) RETURNS decimal(8,2)
BEGIN
    IF marge IS NOT NULL THEN
        RETURN price + price * marge / 100;
    END IF;
    IF fixed IS NOT NULL THEN
        RETURN price + fixed;
    END IF;
    IF final_price IS NOT NULL THEN
        RETURN final_price;
    END IF;
    RETURN price;
END
        ";
        
        if ( ! mysqli_query($connection, $createCalPriceFunctionQuery)) {
            throw new \Exception("Failed to create calculating price function");
        }
        
        mysqli_close($connection);
        
        $installer->endSetup();
    }
}
