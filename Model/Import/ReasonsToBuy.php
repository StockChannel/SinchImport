<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class ReasonsToBuy extends AbstractImportSection {
    const LOG_PREFIX = "ReasonsToBuy: ";
    const LOG_FILENAME = "reasons_to_buy";

    private $reasonsToBuyTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->reasonsToBuyTable = $this->getTableName('sinch_reasons_to_buy');
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_REASONS_TO_BUY];
    }

    public function parse()
    {
        $this->createTableIfRequired();
        $conn = $this->getConnection();
        $reasonsToBuyCsv = $this->dlHelper->getSavePath(Download::FILE_REASONS_TO_BUY);

        $this->startTimingStep('Load Bullet Points');
        $conn->query("DELETE FROM {$this->reasonsToBuyTable}");
        //ID|No|Value
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$reasonsToBuyCsv}'
                INTO TABLE {$this->reasonsToBuyTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, number, value)"
        );
        $this->endTimingStep();

        //TODO: Do something with the loaded reasons
        $this->timingPrint();
    }

    private function createTableIfRequired()
    {
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->reasonsToBuyTable} (
                id int(10) unsigned NOT NULL COMMENT 'Reasons to Buy ID',
                number int(10) unsigned NOT NULL COMMENT 'Reason Number',
                value text,
                PRIMARY KEY (id, number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}