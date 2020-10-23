<?php


namespace SITC\Sinchimport\Model\Import;

/**
 * Class StockPrice
 * @package SITC\Sinchimport\Model\Import
 * Implements stock handling
 */
class StockPrice extends AbstractImportSection
{
    const STOCK_IMPORT_TABLE = 'sinch_stock_and_prices';
    const DISTI_TABLE = 'sinch_distributors';
    const DISTI_STOCK_IMPORT_TABLE = 'sinch_distributors_stock_and_price';

    /** @var \SITC\Sinchimport\Helper\Data */
    private $helper;
    /** @var \Magento\CatalogInventory\Api\StockConfigurationInterface */
    private $stockConfiguration;

    private $stockImportTable;
    private $distiTable;
    private $distiStockImportTable;
    private $importStatsTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \SITC\Sinchimport\Helper\Data $helper,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
    ){
        parent::__construct($resourceConn, $output);
        $this->helper = $helper;
        $this->stockConfiguration = $stockConfiguration;

        $this->stockImportTable = $this->getTableName(self::STOCK_IMPORT_TABLE);
        $this->distiTable = $this->getTableName(self::DISTI_TABLE);
        $this->distiStockImportTable = $this->getTableName(self::DISTI_STOCK_IMPORT_TABLE);
        $this->importStatsTable = $this->getTableName('sinch_import_status_statistic');
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

        //Load distributors
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

        //Load stock and price data
        $conn->query("DELETE FROM {$this->stockImportTable}");
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$stockAndPricesCsv}'
                INTO TABLE {$this->stockImportTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (store_product_id, stock, @price, @cost, distributor_id)
                SET price = REPLACE(@price, ',', '.'),
                    cost = REPLACE(@cost, ',', '.')"
        );

        //Load distributor stock data
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


    }

    /**
     * Apply the new stock and price information to the Magento tables
     * @return void
     */
    public function apply()
    {
        $conn = $this->getConnection();
        $catalogInvStockItem = $this->getTableName('cataloginventory_stock_item');
        $catalogProductEntity = $this->getTableName('catalog_product_entity');
        $catalogProductEntityDecimal = $this->getTableName('catalog_product_entity_decimal');
        $prodWebTemp = $this->getTableName('products_website_temp');

        //Delete stock entries for non-existent products
        $conn->query("DELETE csi FROM {$catalogInvStockItem} csi
            LEFT JOIN {$catalogProductEntity} cpe
                ON csi.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        //Set stock to 0 for sinch products not present in the new data
        $conn->query("UPDATE {$catalogInvStockItem} csi
            INNER JOIN {$catalogProductEntity} cpe
                ON cpe.entity_id = csi.product_id
            LEFT JOIN {$this->stockImportTable} st
                ON st.store_product_id = cpe.store_product_id
            SET csi.qty = 0,
                csi.is_in_stock = 0
            WHERE cpe.store_product_id IS NOT NULL
                AND st.stock IS NULL"
        );

        /* The website_id used in cataloginventory_stock_item serves no purpose and setting it to anything but
            the value of \Magento\CatalogInventory\Api\StockConfigurationInterface->getDefaultScopeId() only serves to break the checkout process */
        $stockItemScope = $this->stockConfiguration->getDefaultScopeId();

        //Insert new sinch stock levels
        $conn->query("INSERT INTO {$catalogInvStockItem} (product_id, stock_id, qty, is_in_stock, manage_stock, website_id) (
            SELECT a.entity_id, 1, b.stock, IF(b.stock > 0, 1, 0), 0, {$stockItemScope} FROM {$catalogProductEntity} a
                INNER JOIN {$this->stockImportTable} b
                    ON a.store_product_id = b.store_product_id
        ) ON DUPLICATE KEY UPDATE qty = b.stock, is_in_stock = IF(b.stock > 0, 1, 0), manage_stock = 1");

        //TODO: Make sure to invalidate the cataloginventory_stock indexer so cataloginventory_stock_status is built

        $priceAttrId = $this->getProductAttributeId('price');
        $costAttrId = $this->getProductAttributeId('cost');

        //Add price (global)
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$priceAttrId}, 0, cpe.entity_id, st.price FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
        ) ON DUPLICATE KEY UPDATE value = st.price");

        //Add price (website)
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$priceAttrId}, w.website, cpe.entity_id, st.price FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
                INNER JOIN {$prodWebTemp} w
                    ON cpe.store_product_id = w.store_product_id
        ) ON DUPLICATE KEY UPDATE value = st.price");

        //Add cost (global)
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$costAttrId}, 0, cpe.entity_id, st.cost FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st ON cpe.store_product_id = st.product_id
        ) ON DUPLICATE KEY UPDATE value = st.cost");

        //Add cost (website)
        $conn->query("INSERT INTO {$catalogProductEntityDecimal} (attribute_id, store_id, entity_id, value) (
            SELECT {$costAttrId}, w.website, cpe.entity_id, st.cost FROM {$catalogProductEntity} cpe
                INNER JOIN {$this->stockImportTable} st
                    ON cpe.store_product_id = st.product_id
                INNER JOIN {$prodWebTemp} w
                    ON cpe.store_product_id = w.store_product_id
        ) ON DUPLICATE KEY UPDATE value = st.cost");


        //Update import statistics with product count
        $conn->query("UPDATE {$this->importStatsTable}
            SET number_of_products = (
                SELECT COUNT(*) FROM {$catalogProductEntity} cpe
                    INNER JOIN {$this->stockImportTable} st
                        ON cpe.store_product_id = st.product_id
            )
            WHERE id = (SELECT MAX(id) FROM {$this->importStatsTable})"
        );
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
}