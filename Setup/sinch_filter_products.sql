DROP PROCEDURE IF EXISTS `sinch_filter_products`;
delimiter =/=

CREATE PROCEDURE `sinch_filter_products` (
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
            ANY_VALUE(PR.product_name),
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
            ANY_VALUE(PR.product_name),
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
END=/=

delimiter ;
