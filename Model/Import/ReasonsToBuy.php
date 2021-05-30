<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class ReasonsToBuy extends AbstractImportSection {
    const LOG_PREFIX = "ReasonsToBuy: ";
    const LOG_FILENAME = "reasons_to_buy";

    private $dataHelper;

    private $reasonsToBuyTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;

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

        $this->startTimingStep('Load Reasons to Buy');
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
    }

    public function apply()
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_text = $this->getTableName('catalog_product_entity_text');

        $reasonsToBuyAttr = $this->dataHelper->getProductAttributeId('sinch_reasons_to_buy');

        //Insert global values for Reasons to Buy
        $this->startTimingStep('Insert Reasons to Buy values');
        //Triple pipe delimited to reduce the likelihood of colliding with text in the value
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                SELECT :reasonsToBuyAttr, 0, cpe.entity_id, GROUP_CONCAT(srtb.value ORDER BY srtb.number SEPARATOR '|||')
                FROM {$this->reasonsToBuyTable} srtb
                INNER JOIN {$catalog_product_entity} cpe
                    ON srtb.id = cpe.sinch_product_id
                GROUP BY srtb.id, cpe.entity_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":reasonsToBuyAttr" => $reasonsToBuyAttr]
        );
        $this->endTimingStep();

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