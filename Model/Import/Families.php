<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class Families extends AbstractImportSection {
    const LOG_PREFIX = "Families: ";
    const LOG_FILENAME = "families";

    private $dataHelper;

    private $familyTable;
    private $familySeriesTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;

        $this->familyTable = $this->getTableName('sinch_family');
        $this->familySeriesTable = $this->getTableName('sinch_family_series');
    }

    public function getRequiredFiles(): array
    {
        return [
            Download::FILE_FAMILIES,
            Download::FILE_FAMILY_SERIES
        ];
    }

    public function parse()
    {
        $this->createTableIfRequired();
        $conn = $this->getConnection();
        $familiesCsv = $this->dlHelper->getSavePath(Download::FILE_FAMILIES);
        $familySeriesCsv = $this->dlHelper->getSavePath(Download::FILE_FAMILY_SERIES);

        $this->startTimingStep('Load Families');
        $conn->query("DELETE FROM {$this->familyTable}");
        //ID|ParentID|Name
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$familiesCsv}'
                INTO TABLE {$this->familyTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, parent_id, name)"
        );
        $this->endTimingStep();

        $this->startTimingStep('Load Family Series');
        $conn->query("DELETE FROM {$this->familySeriesTable}");
        //ID|Name
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$familySeriesCsv}'
                INTO TABLE {$this->familySeriesTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, name)"
        );
        $this->endTimingStep();

        //Load the valid values into the eav tables so they're valid selections for our attribute
        $familyAttr = $this->dataHelper->getProductAttributeId('sinch_family');
        $familySeriesAttr = $this->dataHelper->getProductAttributeId('sinch_family_series');

        $eav_attribute_option = $this->getTableName('eav_attribute_option');
        $eav_attribute_option_value = $this->getTableName('eav_attribute_option_value');

        $this->startTimingStep('Delete Family values that no longer feature in the new file');
        $conn->query(
            "DELETE aov
                FROM {$eav_attribute_option} ao
                JOIN {$eav_attribute_option_value} aov
                    ON ao.option_id = aov.option_id
                LEFT JOIN {$this->familyTable} sf
                    ON aov.value = sf.name
                WHERE
                    ao.attribute_id = :familyAttr AND
                    sf.name IS NULL",
            [':familyAttr' => $familyAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Delete Family Series values that no longer feature in the new file');
        $conn->query(
            "DELETE aov
                FROM {$eav_attribute_option} ao
                JOIN {$eav_attribute_option_value} aov
                    ON ao.option_id = aov.option_id
                LEFT JOIN {$this->familySeriesTable} sfs
                    ON aov.value = sfs.name
                WHERE
                    ao.attribute_id = :familySeriesAttr AND
                    sfs.name IS NULL",
            [':familySeriesAttr' => $familySeriesAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Delete Family and Family Series options that have no values');
        $conn->query(
            "DELETE ao
                FROM {$eav_attribute_option} ao
                LEFT JOIN {$eav_attribute_option_value} aov
                    ON ao.option_id = aov.option_id
                WHERE
                    (attribute_id = :familyAttr OR attribute_id = :familySeriesAttr) AND
                    aov.option_id IS NULL",
            [':familyAttr' => $familyAttr,':familySeriesAttr' => $familySeriesAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert missing Families');
        //Get Families with missing values
        $res = $conn->fetchAll(
            "SELECT sf.id, sf.name
                FROM {$this->familyTable} sf
                LEFT JOIN {$eav_attribute_option_value} aov
                    ON sf.name = aov.value
                WHERE aov.value IS NULL"
        );

        //Insert missing Family names
        foreach ($res as $row) {
            $conn->query(
                "INSERT INTO {$eav_attribute_option} (attribute_id) VALUES(:familyAttr)",
                [':familyAttr' => $familyAttr]
            );
            $conn->query(
                "INSERT INTO {$eav_attribute_option_value} (option_id, value) VALUES(LAST_INSERT_ID(), :familyName)",
                [':familyName' => $row['name']]
            );
        }
        $this->endTimingStep();

        $this->startTimingStep('Insert missing Family Series');
        //Get Family Series with missing values
        $res = $conn->fetchAll(
            "SELECT sfs.id, sfs.name
                FROM {$this->familySeriesTable} sfs
                LEFT JOIN {$eav_attribute_option_value} aov
                    ON sfs.name = aov.value
                WHERE aov.value IS NULL"
        );

        //Insert missing Family Series names
        foreach ($res as $row) {
            $conn->query(
                "INSERT INTO {$eav_attribute_option} (attribute_id) VALUES(:familySeriesAttr)",
                [':familySeriesAttr' => $familySeriesAttr]
            );
            $conn->query(
                "INSERT INTO {$eav_attribute_option_value} (option_id, value) VALUES(LAST_INSERT_ID(), :familySeriesName)",
                [':familySeriesName' => $row['name']]
            );
        }
        $this->endTimingStep();

        $this->startTimingStep('Store Family option IDs ready for apply');
        $conn->query(
            "UPDATE {$this->familyTable} sf
                JOIN {$eav_attribute_option_value} aov
                    ON sf.name = aov.value
                JOIN {$eav_attribute_option} ao
                    ON ao.option_id = aov.option_id
                SET sf.shop_option_id = aov.option_id
                WHERE ao.attribute_id = :familyAttr",
            [':familyAttr' => $familyAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Store Family Series option IDs ready for apply');
        $conn->query(
            "UPDATE {$this->familySeriesTable} sfs
                JOIN {$eav_attribute_option_value} aov
                    ON sfs.name = aov.value
                JOIN {$eav_attribute_option} ao
                    ON ao.option_id = aov.option_id
                SET sfs.shop_option_id = aov.option_id
                WHERE ao.attribute_id = :familySeriesAttr",
            [':familySeriesAttr' => $familySeriesAttr]
        );
        $this->endTimingStep();
    }

    public function apply() {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $sinch_products = $this->getTableName('sinch_products');

        $familyAttr = $this->dataHelper->getProductAttributeId('sinch_family');
        $familySeriesAttr = $this->dataHelper->getProductAttributeId('sinch_family_series');

        //Insert global values for Family
        $this->startTimingStep('Insert Product Family values');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
                SELECT :familyAttr, 0, cpe.entity_id, sf.shop_option_id
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
                LEFT JOIN {$this->familyTable} sf
                    ON sp.family_id = sf.id
            )
            ON DUPLICATE KEY UPDATE
                value = sf.shop_option_id",
            [":familyAttr" => $familyAttr]
        );
        $this->endTimingStep();

        //Insert global values for Family Series
        $this->startTimingStep('Insert Product Family Series values');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
                SELECT :familySeriesAttr, 0, cpe.entity_id, sfs.shop_option_id
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
                LEFT JOIN {$this->familySeriesTable} sfs
                    ON sp.series_id = sfs.id
            )
            ON DUPLICATE KEY UPDATE
                value = sfs.shop_option_id",
            [":familySeriesAttr" => $familySeriesAttr]
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    private function createTableIfRequired()
    {
        $conn = $this->getConnection();
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$this->familyTable} (
                id int(10) unsigned NOT NULL COMMENT 'Sinch Family ID' PRIMARY KEY,
                parent_id int(10) unsigned COMMENT 'Parent Family ID',
                name varchar(255),
                shop_option_id int(10) unsigned COMMENT 'Magento Option ID'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$this->familySeriesTable} (
                id int(10) unsigned NOT NULL COMMENT 'Sinch Family Series ID' PRIMARY KEY,
                name varchar(255),
                shop_option_id int(10) unsigned COMMENT 'Magento Option ID'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}