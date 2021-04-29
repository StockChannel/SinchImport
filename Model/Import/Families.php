<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class Families extends AbstractImportSection {
    const LOG_PREFIX = "Families: ";
    const LOG_FILENAME = "families";

    private $familyTable;
    private $familySeriesTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
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

        //TODO: Do something with the families data

        $this->timingPrint();
    }

    private function createTableIfRequired()
    {
        $conn = $this->getConnection();
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$this->familyTable} (
                id int(10) unsigned NOT NULL COMMENT 'Sinch Family ID' PRIMARY KEY,
                parent_id int(10) unsigned COMMENT 'Parent Family ID',
                name varchar(255)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$this->familySeriesTable} (
                id int(10) unsigned NOT NULL COMMENT 'Sinch Family Series ID' PRIMARY KEY,
                name varchar(255)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}