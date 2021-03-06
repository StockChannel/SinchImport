<?php


namespace SITC\Sinchimport\Model\Import;

/**
 * Class StockPrice
 * @package SITC\Sinchimport\Model\Import
 * Implements stock handling
 */
class StockPrice extends AbstractImportSection
{
    const LOG_PREFIX = "StockPrice: ";
    const LOG_FILENAME = "stockprice";

    const STOCK_IMPORT_TABLE = 'sinch_stock_and_prices';
    const DISTI_TABLE = 'sinch_distributors';
    const DISTI_STOCK_IMPORT_TABLE = 'sinch_distributors_stock_and_price';
    const SINCH_PRODUCTS_TABLE = 'sinch_products';

    /** @var \SITC\Sinchimport\Helper\Data */
    private $helper;
    /** @var \Magento\CatalogInventory\Api\StockConfigurationInterface */
    private $stockConfiguration;
    /** @var IndexManagement */
    private $indexManagement;
    /** @var \Magento\InventoryApi\Api\StockRepositoryInterface */
    private $stockRepo;
    /** @var \Magento\InventoryApi\Api\Data\StockInterfaceFactory */
    private $stockFactory;

    private $stockImportTable;
    private $distiTable;
    private $distiStockImportTable;
    private $importStatsTable;
    private $sinchProductsTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \SITC\Sinchimport\Helper\Data $helper,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        IndexManagement $indexManagement,
        \Magento\InventoryApi\Api\StockRepositoryInterface\Proxy $stockRepo,
        \Magento\InventoryApi\Api\Data\StockInterfaceFactory\Proxy $stockFactory
    ){
        parent::__construct($resourceConn, $output);
        $this->helper = $helper;
        $this->stockConfiguration = $stockConfiguration;
        $this->indexManagement = $indexManagement;
        $this->stockRepo = $stockRepo;
        $this->stockFactory = $stockFactory;

        $this->stockImportTable = $this->getTableName(self::STOCK_IMPORT_TABLE);
        $this->distiTable = $this->getTableName(self::DISTI_TABLE);
        $this->distiStockImportTable = $this->getTableName(self::DISTI_STOCK_IMPORT_TABLE);
        $this->importStatsTable = $this->getTableName('sinch_import_status_statistic');
        $this->sinchProductsTable = $this->getTableName(self::SINCH_PRODUCTS_TABLE);
    }

    /**
     * Parse the stock files
     * @param string $stockAndPricesCsv StockAndPrices.csv
     * @param string $distributorsCsv Distributors.csv
     * @param string $distiStockAndPricesCsv DistributorStockAndPrices.csv
     */
    public function parse(string $stockAndPricesCsv, string $distributorsCsv, string $distiStockAndPricesCsv)
    {
        $conn = $this->getConnection();

        $this->startTimingStep('Load distributors');
        $conn->query("DELETE FROM {$this->distiTable}");
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$distributorsCsv}'
                INTO TABLE {$this->distiTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (distributor_id, distributor_name, website)"
        );
        $this->endTimingStep();

        $this->startTimingStep('Load stock and price data');
        $conn->query("DELETE FROM {$this->stockImportTable}");
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$stockAndPricesCsv}'
                INTO TABLE {$this->stockImportTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (product_id, stock, @price, @cost, distributor_id)
                SET price = REPLACE(@price, ',', '.'),
                    cost = REPLACE(@cost, ',', '.')"
        );
        $this->endTimingStep();

        $this->startTimingStep('Load distributor stock data');
        $conn->query("DELETE FROM {$this->distiStockImportTable}");
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$distiStockAndPricesCsv}'
                INTO TABLE {$this->distiStockImportTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (product_id, distributor_id, stock, @cost, @distributor_sku, @distributor_category, @eta, @brand_sku)"
        );
        $this->endTimingStep();

    }

    /**
     * Uses the distributor stock and price information to populate the supplier_{1,2,3,4,5} attributes
     * @return void
     */
    public function applyDistributors()
    {
        $this->startTimingStep('Apply distributors prep');
        $conn = $this->getConnection();
        $catalogProductEntityVarchar = $this->getTableName('catalog_product_entity_varchar');
        $catalogProductEntity = $this->getTableName('catalog_product_entity');
        $productsWebsiteTemp = $this->getTableName('products_website_temp');

        //Holds copy of the data (so we can delete entries as we use them for each supplier attribute)
        $tempTable = $this->getTableName('sinch_distributors_stock_supplier_temp');
        //Holds a single entry per product (this becomes the entry we insert on each loop iteration)
        $tempSingle = $this->getTableName('sinch_distributors_stock_supplier_processing');
        $distiTable = $this->getTableName('sinch_distributors');

        //Drop and recreate the temp table
        $conn->query("DROP TABLE IF EXISTS {$tempTable}");
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$tempTable} (
                `product_id` int(11) NOT NULL,
                `distributor_id` int(11) NOT NULL,
                PRIMARY KEY (`distributor_id`,`product_id`),
                FOREIGN KEY (`distributor_id`) REFERENCES `{$distiTable}` (`distributor_id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        //Copy the content into the temp table
        $conn->query("INSERT INTO {$tempTable} SELECT product_id, distributor_id FROM {$this->distiStockImportTable}");

        //Create the single table
        $conn->query("DROP TABLE IF EXISTS {$tempSingle}");
        $conn->query("CREATE TABLE IF NOT EXISTS {$tempSingle} LIKE {$tempTable}");
        $this->endTimingStep();

        for ($i = 1; $i <= 5; $i++) {
            $this->startTimingStep('Product supplier ' . $i);
            $conn->query("DELETE FROM {$tempSingle}");
            //The group by causes only a single row to be emitted per product (it picks any value for distributor, so supplier order is undefined behaviour)
            $conn->query("INSERT INTO {$tempSingle} SELECT product_id, ANY_VALUE(distributor_id) FROM {$tempTable} GROUP BY product_id");

            $supplierAttrId = $this->getProductAttributeId('supplier_' . $i);
            //Try to clear the attribute value (in case there are less than 5 suppliers for each product, but there was previously more)
            //We just update the value to an empty string, as UPDATE should be faster than DELETE + INSERT, especially with triggers
            $conn->query(
                "UPDATE {$catalogProductEntityVarchar} SET value = '' WHERE attribute_id = :supplierAttrId",
                [":supplierAttrId" => $supplierAttrId]
            );

            // Product Distributors (global scope)
            $conn->query(
                "INSERT INTO {$catalogProductEntityVarchar} (attribute_id, store_id, entity_id, value) (
                    SELECT {$supplierAttrId}, 0, cpe.entity_id, distributors.distributor_name FROM {$catalogProductEntity} cpe
                        INNER JOIN {$tempSingle} supplier
                            ON cpe.store_product_id = supplier.product_id
                        INNER JOIN {$this->distiTable} distributors
                            ON supplier.distributor_id = distributors.distributor_id
                ) ON DUPLICATE KEY UPDATE value = distributors.distributor_name"
            );

            // Product Distributors (website scope)
            $conn->query(
                "INSERT INTO {$catalogProductEntityVarchar} (attribute_id, store_id, entity_id, value) (
                    SELECT {$supplierAttrId}, w.website, cpe.entity_id, distributors.distributor_name FROM {$catalogProductEntity} cpe
                        INNER JOIN {$tempSingle} supplier
                            ON cpe.store_product_id = supplier.product_id
                        INNER JOIN {$this->distiTable} distributors
                            ON supplier.distributor_id = distributors.distributor_id
                        INNER JOIN {$productsWebsiteTemp} w
                            ON cpe.store_product_id = w.store_product_id
                ) ON DUPLICATE KEY UPDATE value = distributors.distributor_name"
            );

            //Remove the supplier we just added the attribute for from the temp table
            $conn->query(
                "DELETE temp FROM {$tempTable} temp
                    INNER JOIN {$tempSingle} single
                        ON temp.product_id = single.product_id
                        AND temp.distributor_id = single.distributor_id"
            );
            $this->endTimingStep();
        }

        //Clean up the temp tables
        $this->startTimingStep('Apply distributors cleanup');
        $conn->query("DROP TABLE IF EXISTS {$tempSingle}");
        $conn->query("DROP TABLE IF EXISTS {$tempTable}");
        $this->endTimingStep();
    }

    /**
     * Apply the new stock and price information to the Magento tables
     * @return void
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Validation\ValidationException
     */
    public function apply()
    {
        $conn = $this->getConnection();
        $catalogProductEntity = $this->getTableName('catalog_product_entity');
        $catalogProductEntityDecimal = $this->getTableName('catalog_product_entity_decimal');
        $prodWebTemp = $this->getTableName('products_website_temp');

        $priceAttrId = $this->getProductAttributeId('price');
        $costAttrId = $this->getProductAttributeId('cost');

        $this->startTimingStep('Add price (global)');
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$priceAttrId}, 0, cpe.entity_id, st.price FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
        ) ON DUPLICATE KEY UPDATE value = st.price");
        $this->endTimingStep();

        $this->startTimingStep('Add price (website)');
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$priceAttrId}, w.website, cpe.entity_id, st.price FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
                INNER JOIN {$prodWebTemp} w
                    ON cpe.store_product_id = w.store_product_id
        ) ON DUPLICATE KEY UPDATE value = st.price");
        $this->endTimingStep();

        $this->startTimingStep('Add cost (global)');
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$costAttrId}, 0, cpe.entity_id, st.cost FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
        ) ON DUPLICATE KEY UPDATE value = st.cost");
        $this->endTimingStep();

        $this->startTimingStep('Add cost (website)');
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$costAttrId}, w.website, cpe.entity_id, st.cost FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
                INNER JOIN {$prodWebTemp} w
                    ON cpe.store_product_id = w.store_product_id
        ) ON DUPLICATE KEY UPDATE value = st.cost");
        $this->endTimingStep();

        $this->startTimingStep('Reindex catalog_product_price');
        $this->indexManagement->runIndex("catalog_product_price");
        $this->endTimingStep();

        //Apply stock changes
        $this->applyStock();

        //Update import statistics with product count
        $conn->query("UPDATE {$this->importStatsTable}
            SET number_of_products = (
                SELECT COUNT(*) FROM {$catalogProductEntity} cpe
                    INNER JOIN {$this->stockImportTable} st
                        ON cpe.store_product_id = st.product_id
            )
            ORDER BY id DESC LIMIT 1"
        );

        //Print timing info
        $this->timingPrint();
    }


    /**
     * Apply Stock
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Validation\ValidationException
     */
    private function applyStock()
    {
        $conn = $this->getConnection();
        //Tables common to both implementations
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        //Defined in this scope as multi-source path needs it too, for inserting marker records
        $cataloginventory_stock_item = $this->getTableName('cataloginventory_stock_item');
        //Defined in this scope as the single source path checks for the existence of this table to determine whether it needs to clear multi-source records
        $inventory_source_item = $this->getTableName('inventory_source_item');
        /* The website_id used in cataloginventory_stock_item serves no purpose and setting it to anything but
            the value of \Magento\CatalogInventory\Api\StockConfigurationInterface->getDefaultScopeId() only serves to break the checkout process */
        $stockItemScope = $this->stockConfiguration->getDefaultScopeId();

        if ($this->helper->isMSIEnabled()) {
            //MSI (or the multi-source setting for the import) enabled, process multi-source
            //Tables
            $inventory_source = $this->getTableName('inventory_source');
            $sinch_distributors = $this->getTableName('sinch_distributors');
            $sinch_distributors_stock_and_price = $this->getTableName('sinch_distributors_stock_and_price');
            $inventory_stock = $this->getTableName('inventory_stock');
            $inventory_source_stock_link = $this->getTableName('inventory_source_stock_link');

            $stockId = $conn->fetchOne("SELECT stock_id FROM {$inventory_stock} WHERE name = 'Sinch'");
            if (!\is_numeric($stockId)) {
                $stockId = $this->createStockSource();
            }
            if (!\is_numeric($stockId)) {
                $this->log("Failed to create sinch stock source");
                throw new \Exception("Failed to create sinch stock source");
            }

            //Ensure that the distributor sources exist
            $this->startTimingStep('Add inventory sources');
            $conn->query(
                "INSERT INTO {$inventory_source} (source_code, name, country_id, postcode) (
                    SELECT CONCAT('sinch_', distributor_id), distributor_name, 'GB', '?' FROM {$sinch_distributors}
                ) ON DUPLICATE KEY UPDATE name = distributor_name"
            );
            $conn->query(
                "INSERT INTO {$inventory_source_stock_link} (stock_id, source_code, priority) (
                    SELECT :stockId, CONCAT('sinch_', distributor_id), 1 FROM {$sinch_distributors}
                ) ON DUPLICATE KEY UPDATE priority = VALUES(priority)",
                [":stockId" => $stockId]
            );
            $this->endTimingStep();

            $this->startTimingStep('Delete stock entries for non-existent products (MSI)');
            $conn->query("DELETE isi FROM {$inventory_source_item} isi
                LEFT JOIN {$catalog_product_entity} cpe
                    ON isi.sku = cpe.sku
                WHERE cpe.entity_id IS NULL"
            );
            $this->endTimingStep();

            $this->startTimingStep('Delete default source records for Sinch products');
            $conn->query(
                "DELETE FROM {$inventory_source_item}
                    WHERE source_code = 'default'
                      AND sku IN (SELECT sku FROM {$catalog_product_entity} WHERE store_product_id IS NOT NULL)"
            );
            $this->endTimingStep();

            $this->startTimingStep('Set stock to 0 for sinch products not present in new data (MSI)');
            //ISI.status is the same as CSI.is_in_stock
//            $conn->query("UPDATE {$inventory_source_item} isi
//                INNER JOIN {$catalog_product_entity} cpe
//                    ON isi.sku = cpe.sku
//                LEFT JOIN {$sinch_distributors_stock_and_price} sdsp
//                    ON cpe.store_product_id = sdsp.product_id
//                SET isi.quantity = 0,
//                    isi.status = 0
//                WHERE cpe.store_product_id IS NOT NULL
//                    AND sdsp.stock IS NULL"
//            );
            //Much faster than the above query (for roughly the same job)
            //$conn->query("UPDATE {$inventory_source_item} SET quantity = 0, status = 0 WHERE sku IN (SELECT sku FROM {$catalog_product_entity} WHERE store_product_id IS NOT NULL)");
            $conn->query(
                "UPDATE {$inventory_source_item}
                    SET quantity = 0, status = 0
                    WHERE sku IN (
                        SELECT cpe.sku FROM {$catalog_product_entity} cpe
                        LEFT JOIN {$this->stockImportTable} ssp
                            ON cpe.store_product_id = ssp.product_id
                        WHERE cpe.store_product_id IS NOT NULL
                          AND (ssp.stock IS NULL OR ssp.stock < 1)
                )"
            );
            $this->endTimingStep();

            $this->startTimingStep('Remove non-existent stock records (MSI)');

            $distributorIds = $conn->fetchCol("SELECT DISTINCT source_code FROM {$inventory_source_item}");

            foreach ($distributorIds as $distributorId) {
                $distributorId = (int)str_replace('sinch_', '', $distributorId);
                $conn->query(
                    "UPDATE {$inventory_source_item} isi SET isi.quantity = 0, isi.status = 0 
                    WHERE isi.source_code = CONCAT('sinch_', :distiId) 
                    AND isi.sku NOT IN 
                        (SELECT sp.product_sku FROM {$sinch_distributors_stock_and_price} sdsp 
                            INNER JOIN {$this->sinchProductsTable} sp ON sdsp.product_id = sp.sinch_product_id 
                        WHERE sdsp.distributor_id = :distiId AND sdsp.stock > 0) AND (isi.quantity > 0 OR isi.status = 1)",
                    ['distiId' => $distributorId]
                    );
            }

            $this->endTimingStep();

            //Create stock records per distributor
            $this->startTimingStep('Insert new stock levels (MSI)');
            $conn->query(
                "INSERT INTO {$inventory_source_item} (source_code, sku, quantity, status) (
                    SELECT CONCAT('sinch_', sdsp.distributor_id), cpe.sku, sdsp.stock, IF(sdsp.stock > 0, 1, 0) FROM {$sinch_distributors_stock_and_price} sdsp  
                        INNER JOIN {$catalog_product_entity} cpe ON sdsp.product_id = cpe.store_product_id
                ) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), status = VALUES(status)"
            );
            $this->endTimingStep();

            $this->startTimingStep('Insert marker records in single-source stock tables');
            //We associate the record into stock_id = 1 to mark the "default source" as containing no stock
            $conn->query("INSERT INTO {$cataloginventory_stock_item} (product_id, stock_id, qty, is_in_stock, manage_stock, website_id) (
                    SELECT cpe.entity_id, 1, NULL, 0, 1, {$stockItemScope} FROM {$catalog_product_entity} cpe
                        INNER JOIN {$this->stockImportTable} ssp
                            ON cpe.store_product_id = ssp.product_id
                ) ON DUPLICATE KEY UPDATE qty = VALUES(qty), is_in_stock = VALUES(is_in_stock), manage_stock = VALUES(manage_stock)"
            );
            $this->endTimingStep();

//            $this->startTimingStep('Insert stock status records'); //TODO: Don't touch index tables
//            $cataloginventory_stock_status = $this->getTableName('cataloginventory_stock_status');
//            $conn->query(
//                "INSERT INTO cataloginventory_stock_status (product_id, website_id, stock_id, qty, stock_status) (
//                  SELECT cpe.entity_id, 0, 1, SUM(isi.quantity), IF(SUM(isi.quantity) > 0, 1, 0) FROM inventory_source_item isi
//                      INNER JOIN catalog_product_entity cpe
//                          ON isi.sku = cpe.sku
//                  GROUP BY cpe.entity_id
//                  HAVING SUM(isi.quantity) > 0
//                 ) ON DUPLICATE KEY UPDATE qty = VALUES(qty), stock_status = VALUES(stock_status)"
//            );
//            $this->endTimingStep();
        } else {
            //Single source (default)
            $this->startTimingStep('Delete stock entries for non-existent products');
            $conn->query("DELETE csi FROM {$cataloginventory_stock_item} csi
                LEFT JOIN {$catalog_product_entity} cpe
                    ON csi.product_id = cpe.entity_id
                WHERE cpe.entity_id IS NULL"
            );
            $this->endTimingStep();

            $this->startTimingStep('Set stock to 0 for sinch products not present in the new data');
            $conn->query("UPDATE {$cataloginventory_stock_item} csi
                INNER JOIN {$catalog_product_entity} cpe
                    ON cpe.entity_id = csi.product_id
                LEFT JOIN {$this->stockImportTable} st
                    ON st.product_id = cpe.store_product_id
                SET csi.qty = 0,
                    csi.is_in_stock = 0
                WHERE cpe.store_product_id IS NOT NULL
                    AND st.stock IS NULL"
            );
            $this->endTimingStep();

            $this->startTimingStep('Insert new stock levels');
            $conn->query("INSERT INTO {$cataloginventory_stock_item} (product_id, stock_id, qty, is_in_stock, manage_stock, website_id) (
                    SELECT cpe.entity_id, 1, ssp.stock, IF(ssp.stock > 0, 1, 0), 1, {$stockItemScope} FROM {$catalog_product_entity} cpe
                        INNER JOIN {$this->stockImportTable} ssp
                            ON cpe.store_product_id = ssp.product_id
                ) ON DUPLICATE KEY UPDATE qty = ssp.stock, is_in_stock = IF(ssp.stock > 0, 1, 0), manage_stock = 1"
            );
            $this->endTimingStep();

            if ($conn->isTableExists($inventory_source_item)) {
                $this->startTimingStep('Delete multi-source records');
                $conn->query("DELETE isi FROM {$inventory_source_item} isi
                    INNER JOIN {$catalog_product_entity} cpe
                        ON isi.sku = cpe.sku
                    WHERE cpe.store_product_id IS NOT NULL");
                $this->endTimingStep();
            }

        }
        //Make sure to invalidate the cataloginventory_stock indexer so cataloginventory_stock_status is built
        $this->startTimingStep('Reindex cataloginventory_stock');
        $this->indexManagement->runIndex("cataloginventory_stock");
        $this->endTimingStep();
        if ($this->helper->isMSIEnabled()) {
            $this->startTimingStep('Reindex inventory');
            $this->indexManagement->runIndex("inventory");
            $this->endTimingStep();
        }
    }

    /**
     * Get the attribute id for the product attribute with the given $attribute_code
     * @param string $attribute_code Attribute code
     * @return int|null
     */
    private function getProductAttributeId(string $attribute_code)
    {
        $eav_entity_type = $this->getTableName('eav_entity_type');
        $productEavTypeId = $this->getConnection()->fetchOne(
            "SELECT entity_type_id FROM {$eav_entity_type} WHERE entity_type_code = :typeCode",
            [":typeCode" => \Magento\Catalog\Model\Product::ENTITY]
        );

        $eav_attribute = $this->getTableName('eav_attribute');
        return $this->getConnection()->fetchOne(
            "SELECT attribute_id FROM {$eav_attribute} WHERE attribute_code = :attrCode AND entity_type_id = :typeId",
            [
                ":typeId" => $productEavTypeId,
                ":attrCode" => $attribute_code
            ]
        );
    }

    /**
     * Creates the sinch stock source, ready for MSI functionality, returning the stock_id
     * @return int Stock ID
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Validation\ValidationException
     */
    private function createStockSource()
    {
        //We use the repository methods to create the source as a view is created with it
        $source = $this->stockFactory->create();
        $source->setName('Sinch');
        return $this->stockRepo->save($source);
    }
}