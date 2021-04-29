<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class BulletPoints extends AbstractImportSection {
    const LOG_PREFIX = "BulletPoints: ";
    const LOG_FILENAME = "bullet_points";

    private $bulletPointsTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
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

        //TODO: Do something with the bullet point data
        //Load into a single attribute (pipe separated or w/e)

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