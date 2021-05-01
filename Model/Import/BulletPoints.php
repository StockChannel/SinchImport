<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class BulletPoints extends AbstractImportSection {
    const LOG_PREFIX = "BulletPoints: ";
    const LOG_FILENAME = "bullet_points";

    private $dataHelper;

    private $bulletPointsTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;

        $this->bulletPointsTable = $this->getTableName('sinch_bullet_points');
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
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');

        $bulletPointsAttr = $this->dataHelper->getProductAttributeId('sinch_bullet_points');

        //Insert global values for Bullet Points
        $this->startTimingStep('Insert Bullet Point values');
        //Triple pipe delimited to reduce the likelihood of colliding with text in the value
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
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