<?php

namespace SITC\Sinchimport\Model\Import;

class Manufacturer extends AbstractImportSection {
    const LOG_PREFIX = "Manufacturer: ";
    const LOG_FILENAME = "manufacturer";

    const ATTRIBUTE_NAME = "manufacturer";
    
    const CSV_FIELD_DELIM = "|";
    const CSV_FIELD_ENCLOSE = '"';
    const CSV_LINE_TERM = "\r\n";

    private $hasParseRun = false;

    //Tables
    //Attribute table
    private $attrTable;
    //Manufacturers table
    private $manufacturersTable;
    //eav_attribute_option table, holds attribute_id => [option_id]
    private $eaoTable;
    //eav_attribute_option_value table, holds option_id => value
    private $eaovTable;
    //sinch_manufacturers table
    private $finalTable;

    //Manufacturer attribute ID
    private $manufacturerAttrId;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output
    ){
        parent::__construct($resourceConn, $output);

        $this->attrTable = $this->getTableName('eav_attribute');
        $this->manufacturersTable = $this->getTableName('manufacturers_temp');
        $this->eaoTable = $this->getTableName('eav_attribute_option');
        $this->eaovTable = $this->getTableName('eav_attribute_option_value');
        $this->finalTable = $this->getTableName('sinch_manufacturers');
        $this->manufacturerAttrId = $this->getConnection()->fetchOne(
            "SELECT attribute_id FROM {$this->attrTable} WHERE attribute_code = :attrName",
            [":attrName" => self::ATTRIBUTE_NAME]
        );
    }

    private function createManufacturersTable()
    {
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->manufacturersTable} (
                sinch_manufacturer_id int(11) NOT NULL PRIMARY KEY,
                manufacturer_name varchar(255) NOT NULL,
                manufacturers_image varchar(255) DEFAULT NULL,
                shop_option_id int(11) DEFAULT NULL,
                KEY (sinch_manufacturer_id),
                KEY (manufacturer_name),
                KEY (shop_option_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }

    /**
     * Parse Manufacturers.csv, loading the values into the manufacturer attribute
     * @param string $manufacturersCsv The path to the CSV file
     */
    public function parse($manufacturersCsv)
    {
        $this->log("--- Begin Manufacturer parse ---");
        $this->createManufacturersTable();

        //Load data
        $conn = $this->getConnection();
        $quotedCsvPath = $conn->quote($manufacturersCsv);
        $conn->query(
            "LOAD DATA LOCAL INFILE {$quotedCsvPath} INTO TABLE {$this->manufacturersTable}
                FIELDS TERMINATED BY '" . self::CSV_FIELD_DELIM . "'
                OPTIONALLY ENCLOSED BY '" . self::CSV_FIELD_ENCLOSE . "'
                LINES TERMINATED BY '" . self::CSV_LINE_TERM . "'
                IGNORE 1 LINES"
        );

        $this->mapExistingValues();
        $missingValues = $this->findMissingValues();

        //Create the missing values (in both eav_attribute_option, and eav_attribute_option_value)
        foreach ($missingValues as $manufacturerName) {
            $conn->query(
                "INSERT INTO {$this->eaoTable} (attribute_id, sort_order) VALUES(:attrId, 0)",
                [":attrId" => $this->manufacturerAttrId]
            );
            //Use LAST_INSERT_ID() to get the value inserted for option_id above ^
            $conn->query(
                "INSERT INTO {$this->eaovTable} (option_id, store_id, value) VALUES(LAST_INSERT_ID(), 0, :manufacturerName)",
                [":manufacturerName" => $manufacturerName]
            );
        }

        //If values were missing, map newly created ones
        // as well as identify any that still haven't been mapped
        if (count($missingValues) > 0) {
            $this->mapExistingValues();
            $stillMissing = $this->findMissingValues();
            $stillMissingCount = count($stillMissing);
            if ($stillMissingCount > 0) {
                $this->log("WARN: {$stillMissingCount} manufacturers failed to map: " . implode(", ", $stillMissing));
            }
        }

        //Remove deleted manufacturers from eav_attribute_option (which will cascade to eav_attribute_option_value)
        $conn->query(
            "DELETE FROM {$this->eaoTable}
                WHERE attribute_id = :attrId
                AND option_id NOT IN (SELECT shop_option_id FROM {$this->manufacturersTable})",
            [":attrId" => $this->manufacturerAttrId]
        );

        
        $conn->query("DROP TABLE IF EXISTS {$this->finalTable}");
        $conn->query("RENAME TABLE {$this->manufacturersTable} TO {$this->finalTable}");

        $this->hasParseRun = true;
        $this->log("--- Completed Manufacturer parse ---");
    }

    public function apply()
    {
        $this->log("--- Begin Manufacturer apply ---");
        $catalogProductEntity = $this->getTableName('catalog_product_entity');
        $cpeInt = $this->getTableName('catalog_product_entity_int');
        $productsTemp = $this->getTableName('products_temp');

        $this->getConnection()->query(
            "INSERT INTO {$cpeInt} (attribute_id, store_id, entity_id, value)
                SELECT :attrId, 0, cpe.entity_id, manufacturers.shop_option_id
                    FROM {$catalogProductEntity} cpe
                    INNER JOIN {$productsTemp} pt
                        ON cpe.sku = pt.product_sku
                    INNER JOIN {$this->finalTable} manufacturers
                        ON pt.sinch_manufacturer_id = manufacturers.sinch_manufacturer_id
                    WHERE manufacturers.shop_option_id IS NOT NULL
            ON DUPLICATE KEY UPDATE
                value = manufacturers.shop_option_id",
            [":attrId" => $this->manufacturerAttrId]
        );
        $this->log("--- Completed Manufacturer apply ---");
    }
    
    /**
     * Map existing values in eav_attribute_option_value (from the 'manufacturer' attribute)
     * updating shop_option_id in the manufacturer table
     * @return void
     */
    private function mapExistingValues()
    {
        // SELECT new.sinch_manufacturer_id, eaov.option_id FROM {$this->manufacturersTable} new
        //         INNER JOIN {$this->eaovTable} eaov
        //             ON new.manufacturer_name = eaov.value AND eaov.option_id IN (SELECT option_id FROM {$this->eaoTable} WHERE attribute_id = :attrId)
        //Mysql doesn't like us binding :attrId inside a subquery within a join condition, so quote attrId directly into it (the SQL itself is valid)
        $this->getConnection()->query(
            "UPDATE {$this->manufacturersTable} new
                INNER JOIN {$this->eaovTable} eaov
                    ON new.manufacturer_name = eaov.value AND eaov.option_id IN (SELECT option_id FROM {$this->eaoTable} WHERE attribute_id = {$this->manufacturerAttrId})
                SET new.shop_option_id = eaov.option_id
                WHERE new.shop_option_id IS NULL
                    OR new.shop_option_id != eaov.option_id"
        );
    }

    /**
     * Find manufacturers with values missing in eav_attribute_option_value
     * Uses manufacturersTable as the source of truth
     * @return string[] The names of the missing manufacturers
     */
    private function findMissingValues()
    {
        return $this->getConnection()->fetchCol(
            "SELECT new.manufacturer_name FROM {$this->manufacturersTable} new
                WHERE new.manufacturer_name NOT IN (
                    SELECT eaov.value FROM {$this->eaovTable} eaov
                    INNER JOIN {$this->eaoTable} eao
                        ON eaov.option_id = eao.option_id
                    WHERE eao.attribute_id = :attrId
                )",
            [":attrId" => $this->manufacturerAttrId]
        );
    }
}