<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class Bundles extends AbstractImportSection
{
    const LOG_PREFIX = "Bundles: ";
    const LOG_FILENAME = "bundles";

    private string $bundleTable;
    private string $bundleCategoryTable;
    private string $bundleGroupTable;
    private string $bundleItemsTable;
    private string $bundleItemsProductTable;
    // Mapping tables
    private string $bundleMappingTable;
    private string $bundleItemsMappingTable;
    private string $bundleItemsProductMappingTable;

    public function __construct(
        ResourceConnection    $resourceConn,
        ConsoleOutput         $output,
        Download              $downloadHelper,
        private readonly Data $dataHelper,
        private readonly IndexManagement $indexManagement,
    )
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->bundleTable = $this->getTableName('sinch_bundles');
        $this->bundleCategoryTable = $this->getTableName('sinch_bundle_categories');
        $this->bundleGroupTable = $this->getTableName('sinch_bundle_groups');
        $this->bundleItemsTable = $this->getTableName('sinch_bundle_items');
        $this->bundleItemsProductTable = $this->getTableName('sinch_bundle_items_products');

        $this->bundleMappingTable = $this->getTableName('sinch_bundle_mapping');
        // Category doesn't need a mapping table, that should be pretty simple with the existing tables
        // Groups doesn't need a mapping table either
        $this->bundleItemsMappingTable = $this->getTableName('sinch_bundle_items_mapping');
        $this->bundleItemsProductMappingTable = $this->getTableName('sinch_bundle_items_product_mapping');
    }

    public function parse(): void
    {
        $this->createTablesIfRequired();

        $bundleFile = $this->dlHelper->getSavePath(Download::FILE_BUNDLES);
        $bundleCatFile = $this->dlHelper->getSavePath(Download::FILE_BUNDLE_CATEGORIES);
        $bundleItemFile = $this->dlHelper->getSavePath(Download::FILE_BUNDLE_ITEMS);
        $bundleItemProdFile = $this->dlHelper->getSavePath(Download::FILE_BUNDLE_ITEMS_PRODUCTS);
        $bundleGroupFile = $this->dlHelper->getSavePath(Download::FILE_BUNDLE_GROUPS);

        $this->startTimingStep('Load Data');
        // Bundles themselves
        // ID|Name|Price|Sku|ImageURL|Visibility
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$bundleFile}'
                INTO TABLE {$this->bundleTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_id, name, price, sku, image_url, visibility)"
        );

        // Bundle Categories
        // BundleID|CategoryID
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$bundleCatFile}'
                INTO TABLE {$this->bundleCategoryTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_bundle_id, sinch_category_id)"
        );

        // Bundle Groups
        // BundleID|AccountGroupID
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$bundleGroupFile}'
                INTO TABLE {$this->bundleGroupTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_bundle_id, sinch_account_group)"
        );

        // Bundle Items
        // ID|BundleID|Title|InputType|Required
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$bundleItemFile}'
                INTO TABLE {$this->bundleItemsTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_item_id, sinch_bundle_id, title, input_type, required)"
        );

        // Bundle Item Products
        // BundleItemID|ProductID|Quantity|Order|IsDefault
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$bundleItemProdFile}'
                INTO TABLE {$this->bundleItemsProductTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_item_id, sinch_product_id, qty, position, is_default)"
        );
        $this->endTimingStep();


        // Begin actual processing
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $catalog_product_entity_text = $this->getTableName('catalog_product_entity_text');
        $catalog_product_entity_varchar = $this->getTableName('catalog_product_entity_varchar');
        $catalog_product_bundle_option = $this->getTableName('catalog_product_bundle_option');
        $catalog_product_bundle_selection = $this->getTableName('catalog_product_bundle_selection');
        $catalog_category_product = $this->getTableName('catalog_category_product');
        $sinch_group_mapping = $this->getTableName('sinch_group_mapping');
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');

        // Start by clearing products which don't feature in the files any more
        // This should also clear the related options, selections, and the values from the mapping table itself
        $this->startTimingStep('Delete removed bundles');
        $this->getConnection()->query(
            "DELETE FROM {$catalog_product_entity}
                WHERE entity_id IN (
                    SELECT magento_id
                    FROM {$this->bundleMappingTable} bm
                    LEFT JOIN {$this->bundleTable} b
                        ON bm.sinch_id = b.sinch_id
                    WHERE b.sinch_id IS NULL
                )"
        );
        $this->endTimingStep();

        // Now bundle items no longer part of the bundle (options)
        // This should also drop selections, and the values from the mapping table
        $this->startTimingStep('Delete removed bundle options');
        $this->getConnection()->query(
            "DELETE FROM {$catalog_product_bundle_option}
                WHERE option_id IN (
                    SELECT magento_option
                    FROM {$this->bundleItemsMappingTable} bim
                    LEFT JOIN {$this->bundleItemsTable} bi
                        ON bim.sinch_id = bi.sinch_id
                    WHERE bi.sinch_id IS NULL
                )"
        );
        $this->endTimingStep();

        // Now products (selections) no longer part of the items (options)
        // Also drops the values from the mapping table
        $this->startTimingStep('Delete removed bundle selections');
        $this->getConnection()->query(
            "DELETE FROM {$catalog_product_bundle_selection}
                WHERE selection_id IN (
                    SELECT magento_selection
                    FROM {$this->bundleItemsProductMappingTable} bipm
                    LEFT JOIN {$this->bundleItemsProductTable} bip
                        ON bipm.sinch_id = bip.sinch_id
                    WHERE bip.sinch_id IS NULL
                )"
        );
        $this->endTimingStep();

        // Now we need to start adding the missing data, starting with the bundles themselves
        $this->startTimingStep('Create missing bundles');
        $attrSet = $this->dataHelper->getDefaultProductAttributeSet();
        // Create the products themselves
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity} (attribute_set_id, type_id, sku, updated_at, has_options, required_options, sinch_product_id) (
                SELECT :attrSet,
                       'bundle',
                       b.sku,
                       NOW(),
                       1,
                       1,
                       b.sinch_id
                FROM {$this->bundleTable} b
                LEFT JOIN {$this->bundleMappingTable} bm
                    ON b.sinch_id = bm.sinch_id
                WHERE bm.sinch_id IS NULL
            )",
            [":attrSet" => $attrSet]
        );
        // Insert the missing records into the mapping table
        $this->getConnection()->query(
            "INSERT INTO {$this->bundleMappingTable} (sinch_id, magento_id) (
                SELECT cpe.sinch_product_id, cpe.entity_id
                FROM {$catalog_product_entity} cpe
                LEFT JOIN {$this->bundleMappingTable} bm
                    ON cpe.sinch_product_id = bm.sinch_id
                WHERE bm.sinch_id IS NULL
            )"
        );
        $this->endTimingStep();

        $mergeMode = $this->dataHelper->productMergeMode();
        $ignore = $mergeMode ? "IGNORE" : "";
        $onDuplicate = $mergeMode ? "" : "ON DUPLICATE KEY UPDATE value = 1";

        $attrStatus = $this->dataHelper->getProductAttributeId('status');
        // Bundle product status
        $this->getConnection()->query(
            "INSERT $ignore INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
                SELECT
                    :attrStatus,
                    0,
                    cpe.entity_id,
                    1
                FROM {$this->bundleMappingTable}
            ) $onDuplicate",
            [":attrStatus" => $attrStatus]
        );

        // Bundle product name
        $onDuplicate = $mergeMode ? "" : "ON DUPLICATE KEY UPDATE value = b.name";

        $attrName = $this->dataHelper->getProductAttributeId('name');
        $this->getConnection()->query(
            "INSERT $ignore INTO {$catalog_product_entity_varchar} (attribute_id, store_id, entity_id, value) (
                SELECT
                    :attrName,
                    0,
                    bm.magento_id,
                    b.name
                FROM {$this->bundleMappingTable} bm
                INNER JOIN {$this->bundleTable} b
                    ON bm.sinch_id = b.sinch_id
            ) $onDuplicate",
            [":attrName" => $attrName]
        );

        // Bundle product visibility
        $onDuplicate = $mergeMode ? "" : "ON DUPLICATE KEY UPDATE value = IF(b.visibility = 1,4,0)";

        $attrVis = $this->dataHelper->getProductAttributeId('visibility');
        $this->getConnection()->query(
            "INSERT $ignore INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
                SELECT
                    :attrVis,
                    0,
                    bm.magento_id,
                    IF(b.visibility = 1,4,0)
                FROM {$this->bundleMappingTable} bm
                INNER JOIN {$this->bundleTable} b
                    ON bm.sinch_id = b.sinch_id
            ) $onDuplicate",
            [":attrVis" => $attrVis]
        );

        // Bundle product tax class
        $onDuplicate = $mergeMode ? "" : "ON DUPLICATE KEY UPDATE value = 2";

        $attrTaxClassId = $this->dataHelper->getProductAttributeId('tax_class_id');
        $this->getConnection()->query(
            "INSERT $ignore INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
                SELECT
                    :attrTaxClassId,
                    0,
                    bm.magento_id,
                    2
                FROM {$this->bundleMappingTable} bm
            ) $onDuplicate",
            [":attrTaxClassId" => $attrTaxClassId]
        );

        // Bundle product image
        $onDuplicate = $mergeMode ? "" : "ON DUPLICATE KEY UPDATE value = b.image_url";

        $attrImage = $this->dataHelper->getProductAttributeId('image');
        $this->getConnection()->query(
            "INSERT $ignore INTO {$catalog_product_entity_varchar} (attribute_id, store_id, entity_id, value) (
                SELECT
                    :attrImage,
                    0,
                    cpe.entity_id,
                    b.image_url
                FROM {$this->bundleMappingTable} bm
                INNER JOIN {$this->bundleTable} b
                    ON bm.sinch_id = b.sinch_id
            ) $onDuplicate",
            [":attrImage" => $attrImage]
        );

        // TODO: Bundle product price
        // Price is going to be phased out of the files as its not used by Magento (at least not in the form the files provide)


        // Now the missing options
        $this->startTimingStep('Create missing bundle options');
        $this->processMissingOptions();
        $this->endTimingStep();

        // Update options' name
        $this->startTimingStep('Update option names');
        $this->updateOptionNames();
        $this->endTimingStep();

        // Update selections settings (position, is_default, qty, can_change_qty)
        $this->startTimingStep('Update selection settings');
        $this->getConnection()->query(
            "UPDATE {$catalog_product_bundle_selection} bs
            INNER JOIN (
                SELECT bipm.magento_selection,
                       bip.position,
                       bip.is_default,
                       bip.qty,
                       0 as can_change_qty
                FROM {$this->bundleItemsProductTable} bip
                INNER JOIN {$this->bundleItemsProductMappingTable} bipm
                    ON bip.sinch_item_id = bipm.sinch_item_id
                    AND bip.sinch_product_id = bipm.sinch_product_id
            ) sub
                ON bs.selection_id = sub.magento_selection
            SET bs.position = sub.position,
                bs.is_default = sub.is_default,
                bs.selection_qty = sub.qty,
                bs.selection_can_change_qty = sub.can_change_qty
            WHERE bs.position != sub.position
            OR bs.is_default != sub.is_default
            OR bs.selection_qty != sub.qty
            OR bs.selection_can_change_qty != sub.can_change_qty"
        );
        $this->endTimingStep();

        // And now the missing products
        $this->startTimingStep('Create missing bundle selections');
        $this->processMissingSelections();
        $this->endTimingStep();

        // Ensure that the bundles are assigned to the correct categories
        $this->startTimingStep('Remove bundles from extraneous categories');
        $this->getConnection()->query(
            "DELETE ccp FROM {$catalog_category_product} ccp
                INNER JOIN {$this->bundleMappingTable} bm
                    ON ccp.product_id = bm.magento_id
                INNER JOIN {$sinch_categories_mapping} scm
                    ON ccp.category_id = scm.shop_entity_id
                LEFT JOIN {$this->bundleCategoryTable} bc
                    ON scm.store_category_id = bc.sinch_category_id
                    AND bm.sinch_id = bc.sinch_bundle_id
                WHERE scm.store_category_id IS NOT NULL
                AND bc.sinch_category_id IS NULL"
        );
        $this->endTimingStep();
        $this->startTimingStep('Add bundles to missing categories');
        $this->getConnection()->query(
            "INSERT IGNORE INTO {$catalog_category_product} (category_id, product_id) (
                SELECT scm.shop_entity_id, bm.magento_id
                FROM {$this->bundleCategoryTable} bc
                INNER JOIN {$sinch_categories_mapping} scm
                    ON bc.sinch_category_id = scm.store_category_id
                INNER JOIN {$this->bundleMappingTable} bm
                    ON bc.sinch_bundle_id = bm.sinch_id
            )"
        );
        $this->endTimingStep();

        // And finally mark them as restricted to the correct groups
        $this->startTimingStep('Restrict bundles to appropriate groups');
        // First the bundles for which no group rules exist
        $sinchRestrictAttr = $this->dataHelper->getProductAttributeId('sinch_restrict');
        $this->getConnection()->query(
            "DELETE FROM {$catalog_product_entity_text}
            WHERE attribute_id = :attrId
            AND entity_id IN (
                SELECT bm.magento_id
                FROM {$this->bundleMappingTable} bm
                INNER JOIN {$this->bundleTable} b
                    ON bm.sinch_id = b.sinch_id
                WHERE NOT EXISTS (
                    SELECT bg.sinch_bundle_id
                    FROM {$this->bundleGroupTable} bg
                    WHERE bg.sinch_bundle_id = b.sinch_id
                )
            )",
            [":attrId" => $sinchRestrictAttr]
        );
        // Now the rest of the bundles
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                SELECT :attrId, 0, bm.magento_id, GROUP_CONCAT(gm.magento_id SEPARATOR ',')
                FROM {$this->bundleGroupTable} bg
                INNER JOIN {$this->bundleMappingTable} bm
                    ON bg.sinch_bundle_id = bm.sinch_id
                INNER JOIN {$sinch_group_mapping} gm
                    ON bg.sinch_account_group = gm.sinch_id
                GROUP BY bg.sinch_bundle_id
            ) ON DUPLICATE KEY UPDATE value = GROUP_CONCAT(gm.magento_id SEPARATOR ',')",
            [":attrId" => $sinchRestrictAttr]
        );
        $this->endTimingStep();

        // TODO: This is only really necessary if we're going to run this section in StockPrice imports as well as full ones
        $this->indexManagement->runIndex('catalog_product_attribute');
    }

    /**
     * @inheritDoc
     */
    public function getRequiredFiles(): array
    {
        return [
            Download::FILE_BUNDLES,
            Download::FILE_BUNDLE_CATEGORIES,
            Download::FILE_BUNDLE_ITEMS,
            Download::FILE_BUNDLE_ITEMS_PRODUCTS,
            Download::FILE_BUNDLE_GROUPS,
        ];
    }

    private function createTablesIfRequired(): void
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $sinch_categories = $this->getTableName('sinch_categories');
        $sinch_group_mapping = $this->getTableName('sinch_group_mapping');
        $sinch_products = $this->getTableName('sinch_products');

        // Bundles
        // ID|Name|Price|Sku|ImageURL|Visibility
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleTable} (
                sinch_id int(10) UNSIGNED NOT NULL PRIMARY KEY,
                name varchar(255) NOT NULL,
                price decimal(10,2) NOT NULL,
                sku varchar(128) NOT NULL UNIQUE,
                image_url varchar(255),
                visibility int(1) NOT NULL DEFAULT 0,
                magento_id int(10) UNSIGNED,
                FOREIGN KEY (magento_id) REFERENCES {$catalog_product_entity} (entity_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        // Bundle Categories
        // BundleID|CategoryID
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleCategoryTable} (
                sinch_bundle_id int(10) UNSIGNED NOT NULL,
                sinch_category_id int(10) UNSIGNED NOT NULL,
                PRIMARY KEY (sinch_bundle_id, sinch_category_id),
                FOREIGN KEY (sinch_bundle_id) REFERENCES {$this->bundleTable} (sinch_id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (sinch_category_id) REFERENCES {$sinch_categories} (store_category_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        // Bundle Groups
        // BundleID|AccountGroupID
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleGroupTable} (
                sinch_bundle_id int(10) UNSIGNED NOT NULL,
                sinch_account_group int(10) UNSIGNED NOT NULL,
                PRIMARY KEY (sinch_bundle_id, sinch_account_group),
                FOREIGN KEY (sinch_bundle_id) REFERENCES {$this->bundleTable} (sinch_id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (sinch_account_group) REFERENCES {$sinch_group_mapping} (sinch_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        // Bundle Items
        // ID|BundleID|Title|InputType|Required
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleItemsTable} (
                sinch_item_id int(10) UNSIGNED NOT NULL PRIMARY KEY,
                sinch_bundle_id int(10) UNSIGNED NOT NULL,
                title varchar(255) NOT NULL,
                input_type varchar(50) NOT NULL,
                required int(1) NOT NULL DEFAULT 0,
                FOREIGN KEY (sinch_bundle_id) REFERENCES {$this->bundleTable} (sinch_id) ON UPDATE CASCADE ON DELETE CASCADE,
            )"
        );

        // Bundle Item Products
        // BundleItemID|ProductID|Quantity|Order|IsDefault
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleItemsProductTable} (
                sinch_item_id int(10) UNSIGNED NOT NULL,
                sinch_product_id int(10) UNSIGNED NOT NULL,
                qty int(10) NOT NULL,
                position int(10) NOT NULL,
                is_default int(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (sinch_item_id, sinch_product_id),
                FOREIGN KEY (sinch_item_id) REFERENCES {$this->bundleItemsTable} (sinch_item_id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (sinch_product_id) REFERENCES {$sinch_products} (sinch_product_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );


        // Mapping tables for keeping track of which entities exist in Magento already
        // Can't have foreign keys (at least to the import tables) as it would preclude us from clearing them for import
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleMappingTable} (
                sinch_id int(10) UNSIGNED NOT NULL PRIMARY KEY,
                magento_id int(10) UNSIGNED NOT NULL UNIQUE,
                FOREIGN KEY (magento_id) REFERENCES {$catalog_product_entity} (entity_id) ON UPDATE CASCADE ON DELETE CASCADE,
            )"
        );

        // This foreign keys to catalog_product_bundle_option
        $catalog_product_bundle_option = $this->getTableName('catalog_product_bundle_option');
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleItemsMappingTable} (
                sinch_id int(10) UNSIGNED NOT NULL PRIMARY KEY,
                magento_option int(10) UNSIGNED NOT NULL UNIQUE,
                FOREIGN KEY (magento_option) REFERENCES {$catalog_product_bundle_option} (option_id) ON UPDATE CASCADE ON DELETE CASCADE,
            )"
        );

        // This foreign keys to catalog_product_bundle_selection
        $catalog_product_bundle_selection = $this->getTableName('catalog_product_bundle_selection');
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bundleItemsProductMappingTable} (
                sinch_item_id int(10) UNSIGNED NOT NULL,
                sinch_product_id int(10) UNSIGNED NOT NULL,
                magento_selection int(10) UNSIGNED NOT NULL UNIQUE,
                PRIMARY KEY (sinch_item_id, sinch_product_id),
                FOREIGN KEY (magento_selection) REFERENCES {$catalog_product_bundle_selection} (selection_id) ON UPDATE CASCADE ON DELETE CASCADE,
            )"
        );
    }

    // Just inserts the missing options themselves, doesn't attempt to do the title (that will be done separately)
    private function processMissingOptions(): void
    {
        $catalog_product_bundle_option = $this->getTableName('catalog_product_bundle_option');

        $missingIds = $this->getConnection()->fetchCol(
            "SELECT sinch_item_id
            FROM {$this->bundleItemsTable} bi
            LEFT JOIN {$this->bundleItemsMappingTable} bim
                ON bi.sinch_item_id = bim.sinch_id
            WHERE bim.sinch_id IS NULL"
        );
        foreach ($missingIds as $missingId) {
            try {
                $this->getConnection()->query(
                    "INSERT INTO {$catalog_product_bundle_option} (parent_id, required, position, type) (
                        SELECT bm.magento_id,
                               bi.required,
                               0,
                               bi.input_type
                        FROM {$this->bundleItemsTable} bi
                        INNER JOIN {$this->bundleMappingTable} bm
                            ON bi.sinch_bundle_id = bm.sinch_id
                        WHERE bi.sinch_item_id = :itemId
                    )",
                    [":itemId" => $missingId]
                );
                // Option itself is inserted, now add it to the mapping table
                $this->getConnection()->query(
                    "INSERT INTO {$this->bundleItemsMappingTable} (sinch_id, magento_option) VALUES(:itemId, LAST_INSERT_ID())",
                    [":itemId" => $missingId]
                );
            } catch (\Exception $e) {
                $this->logger->warning("Got exception while inserting missing option ($missingId): " . $e->getMessage());
            }
        }
    }

    // Just updates the title value for the options
    private function updateOptionNames(): void
    {
        $catalog_product_bundle_option_value = $this->getTableName('catalog_product_bundle_option_value');

        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_bundle_option_value} (option_id, store_id, title, parent_product_id) (
                SELECT bim.magento_option,
                       0,
                       bi.title,
                       bm.magento_id
                FROM {$this->bundleItemsTable} bi
                INNER JOIN {$this->bundleItemsMappingTable} bim
                    ON bi.sinch_item_id = bim.sinch_id
                INNER JOIN {$this->bundleMappingTable} bm
                    ON bi.sinch_bundle_id = bm.sinch_id
            ) ON DUPLICATE KEY UPDATE title = bi.title"
        );
    }

    // Create missing selections for options. Doesn't attempt to set price type or value
    private function processMissingSelections(): void
    {
        $catalog_product_bundle_selection = $this->getTableName('catalog_product_bundle_selection');
        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');

        $missingIds = $this->getConnection()->fetchAll(
            "SELECT sinch_item_id, sinch_product_id
            FROM {$this->bundleItemsProductTable} bip
            LEFT JOIN {$this->bundleItemsProductMappingTable} bipm
                ON bip.sinch_item_id = bipm.sinch_item_id
                AND bip.sinch_product_id = bipm.sinch_product_id
            WHERE bipm.sinch_product_id IS NULL"
        );

        foreach ($missingIds as $missingId) {
            try {
                $this->getConnection()->query(
                    "INSERT INTO {$catalog_product_bundle_selection} (option_id, parent_product_id, product_id, position, is_default, selection_qty, selection_can_change_qty) (
                        SELECT bim.magento_option,
                               bm.magento_id,
                               spm.entity_id,
                               bip.position,
                               bip.is_default,
                               bip.qty,
                               0
                        FROM {$this->bundleItemsProductTable} bip
                        INNER JOIN {$this->bundleItemsTable} bi
                            ON bip.sinch_item_id = bim.sinch_id
                        INNER JOIN {$this->bundleMappingTable} bm
                            ON bi.sinch_bundle_id = bm.sinch_id
                        INNER JOIN {$sinch_products_mapping} spm
                            ON bip.sinch_product_id = spm.sinch_product_id
                        WHERE bip.sinch_item_id = :itemId
                        AND bip.sinch_product_id = :productId
                    )",
                    [":itemId" => $missingId["sinch_item_id"], ":productId" => $missingId["sinch_product_id"]]
                );
                // Selection inserted, now insert into the mapping table
                $this->getConnection()->query(
                    "INSERT INTO {$this->bundleItemsProductMappingTable} (sinch_item_id, sinch_product_id, magento_selection) VALUES(:itemId, :productId, LAST_INSERT_ID())",
                    [":itemId" => $missingId["sinch_item_id"], ":productId" => $missingId["sinch_product_id"]]
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    "Got exception while inserting missing selection (sinch_option = {$missingId["sinch_item_id"]}, sinch_product = {$missingId["sinch_product_id"]}): " . $e->getMessage()
                );
            }
        }
    }
}