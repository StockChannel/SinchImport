<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Handles bullet points as well as the product summary points for display on listing pages
 * @package SITC\Sinchimport\Model\Import
 */
class BulletPoints extends AbstractImportSection {
    const LOG_PREFIX = "BulletPoints: ";
    const LOG_FILENAME = "bullet_points";

    private Data $dataHelper;

    private string $bulletPointsTable;
    private string $sinchProductsTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;

        $this->bulletPointsTable = $this->getTableName('sinch_bullet_points');
        $this->sinchProductsTable = $this->getTableName('sinch_products');
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_BULLET_POINTS];
    }

    public function parse()
    {
        $this->createTableIfRequired();
        $conn = $this->getConnection();
        $bulletPointCsv = $this->dlHelper->getSavePath(Download::FILE_BULLET_POINTS);

        $this->startTimingStep('Load Bullet Points');
        $conn->query("DELETE FROM {$this->bulletPointsTable}");
        //ID|No|Value
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$bulletPointCsv}'
                INTO TABLE {$this->bulletPointsTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, number, value)"
        );
        $this->endTimingStep();
    }

    public function apply() {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_text = $this->getTableName('catalog_product_entity_text');

        $bulletPointsAttr = $this->dataHelper->getProductAttributeId('sinch_bullet_points');

        //Insert global values for Bullet Points
        $this->startTimingStep('Insert Bullet Point values');
        //Triple pipe delimited to reduce the likelihood of colliding with text in the value
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                SELECT :bulletPointsAttr, 0, cpe.entity_id, GROUP_CONCAT(sbp.value ORDER BY sbp.number SEPARATOR '|||')
                FROM {$this->bulletPointsTable} sbp
                INNER JOIN {$catalog_product_entity} cpe
                    ON sbp.id = cpe.sinch_product_id
                GROUP BY sbp.id, cpe.entity_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":bulletPointsAttr" => $bulletPointsAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert Product Summary values');
        $summaryVals = [
            "list_summary_title_1" => $this->dataHelper->getProductAttributeId('sinch_summary_title_1'),
            "list_summary_value_1" => $this->dataHelper->getProductAttributeId('sinch_summary_value_1'),
            "list_summary_title_2" => $this->dataHelper->getProductAttributeId('sinch_summary_title_2'),
            "list_summary_value_2" => $this->dataHelper->getProductAttributeId('sinch_summary_value_2'),
            "list_summary_title_3" => $this->dataHelper->getProductAttributeId('sinch_summary_title_3'),
            "list_summary_value_3" => $this->dataHelper->getProductAttributeId('sinch_summary_value_3'),
            "list_summary_title_4" => $this->dataHelper->getProductAttributeId('sinch_summary_title_4'),
            "list_summary_value_4" => $this->dataHelper->getProductAttributeId('sinch_summary_value_4')
        ];
        foreach ($summaryVals as $field => $attributeId) {
            $this->getConnection()->query(
                "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                    SELECT :summaryAttr, 0, cpe.entity_id, sp.{$field}
                    FROM {$this->sinchProductsTable} sp
                    INNER JOIN {$catalog_product_entity} cpe
                        ON sp.sinch_product_id = cpe.sinch_product_id
                )
                ON DUPLICATE KEY UPDATE
                    value = VALUES(value)",
                [":summaryAttr" => $attributeId]
            );
        }
        $this->endTimingStep();

        $this->timingPrint();
    }

    private function createTableIfRequired()
    {
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->bulletPointsTable} (
                id int(10) unsigned NOT NULL COMMENT 'Bullet Point ID',
                number int(10) unsigned NOT NULL COMMENT 'Bullet Point Number',
                value text,
                PRIMARY KEY (id, number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}