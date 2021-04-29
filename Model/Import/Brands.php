<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;
use Symfony\Component\Console\Output\ConsoleOutput;

class Brands extends AbstractImportSection {
    const LOG_PREFIX = "Brands: ";
    const LOG_FILENAME = "brands";

    /** @var Data */
    private $dataHelper;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_BRANDS];
    }

    /**
     * Replaces parseManufacturers
     */
    public function parse()
    {
        $parseFile = $this->dlHelper->getSavePath(Download::FILE_BRANDS);

        $manufacturers_temp = $this->getTableName('manufacturers_temp');
        $eav_attribute_option = $this->getTableName('eav_attribute_option');
        $eav_attribute_option_value = $this->getTableName('eav_attribute_option_value');

        $conn = $this->getConnection();

        $this->log("Start parse " . Download::FILE_BRANDS);

        $this->startTimingStep('Create Manufacturers temp table');
        $conn->query("DROP TABLE IF EXISTS {$manufacturers_temp}");
        $conn->query(
            "CREATE TABLE {$manufacturers_temp} (
                sinch_manufacturer_id int(11),
                manufacturer_name varchar(255),
                shop_option_id int(11),
                KEY(sinch_manufacturer_id),
                KEY(shop_option_id),
                KEY(manufacturer_name)
            )"
        );
        $this->endTimingStep();

        $this->startTimingStep('Load Manufacturers');
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$parseFile}'
              INTO TABLE {$manufacturers_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_manufacturer_id, manufacturer_name)"
        );
        $this->endTimingStep();

        $manufacturerAttr = $this->dataHelper->getProductAttributeId('manufacturer');

        $this->startTimingStep('Delete manufacturer values that no longer feature in the new file');
        $conn->query(
            "DELETE aov
                FROM {$eav_attribute_option} ao
                JOIN {$eav_attribute_option_value} aov
                    ON ao.option_id = aov.option_id
                LEFT JOIN {$manufacturers_temp} mt
                    ON aov.value = mt.manufacturer_name
                WHERE
                    ao.attribute_id = :manufacturerAttr AND
                    mt.manufacturer_name IS NULL",
            [':manufacturerAttr' => $manufacturerAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Delete manufacturer options that have no values');
        $conn->query(
            "DELETE ao
                FROM {$eav_attribute_option} ao
                LEFT JOIN {$eav_attribute_option_value} aov
                    ON ao.option_id = aov.option_id
                WHERE
                    attribute_id = :manufacturerAttr AND
                    aov.option_id IS NULL",
            [':manufacturerAttr' => $manufacturerAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert missing manufacturers');
        //Get manufacturers with missing values
        $res = $conn->fetchAll(
            "SELECT mt.sinch_manufacturer_id, mt.manufacturer_name
                FROM {$manufacturers_temp} mt
                LEFT JOIN {$eav_attribute_option_value} aov
                    ON mt.manufacturer_name = aov.value
                WHERE aov.value IS NULL"
        );

        //Insert missing Manufacturer names
        foreach ($res as $row) {
            $conn->query(
                "INSERT INTO {$eav_attribute_option} (attribute_id) VALUES(:manufacturerAttr)",
                [':manufacturerAttr' => $manufacturerAttr]
            );
            $conn->query(
                "INSERT INTO {$eav_attribute_option_value} (option_id, value) VALUES(LAST_INSERT_ID(), :manufacturerName)",
                [':manufacturerName' => $row['manufacturer_name']]
            );
        }
        $this->endTimingStep();

        $this->startTimingStep('Store option IDs ready for apply');
        $conn->query(
            "UPDATE {$manufacturers_temp} mt
                JOIN {$eav_attribute_option_value} aov
                    ON mt.manufacturer_name = aov.value
                JOIN {$eav_attribute_option} ao
                    ON ao.option_id = aov.option_id
                SET mt.shop_option_id = aov.option_id
                WHERE ao.attribute_id = :manufacturerAttr",
            [':manufacturerAttr' => $manufacturerAttr]
        );
        $this->endTimingStep();

        $sinch_manufacturers = $this->getTableName('sinch_manufacturers');
        $conn->query("DROP TABLE IF EXISTS {$sinch_manufacturers}");
        $conn->query("RENAME TABLE {$manufacturers_temp} TO {$sinch_manufacturers}");
        $this->log("Finish parse " . Download::FILE_BRANDS);
        $this->timingPrint();
    }

    public function apply() {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $products_temp = $this->getTableName('products_temp');
        $sinch_manufacturers = $this->getTableName('sinch_manufacturers');

        $manufacturerAttr = $this->dataHelper->getProductAttributeId('manufacturer');

        //Insert global values
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value)(
                SELECT :manufacturerAttr, 0, cpe.entity_id, sm.shop_option_id
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$products_temp} pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
                INNER JOIN {$sinch_manufacturers} sm
                    ON pt.sinch_manufacturer_id = sm.sinch_manufacturer_id
                WHERE sm.shop_option_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = sm.shop_option_id",
            [":manufacturerAttr" => $manufacturerAttr]
        );
    }
}