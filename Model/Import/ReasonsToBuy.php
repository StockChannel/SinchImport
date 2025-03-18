<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class ReasonsToBuy extends AbstractImportSection {
    const LOG_PREFIX = "ReasonsToBuy: ";
    const LOG_FILENAME = "reasons_to_buy";

    private Data $dataHelper;

    private string $reasonsToBuyTable;

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

    public function parse(): void
    {
        $this->createTableIfRequired();
        $conn = $this->getConnection();
        $reasonsToBuyCsv = $this->dlHelper->getSavePath(Download::FILE_REASONS_TO_BUY);

        $this->startTimingStep('Load Reasons to Buy');
        /** @noinspection SqlWithoutWhere */
        $conn->query("DELETE FROM {$this->reasonsToBuyTable}");
        //ID|No|Value
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$reasonsToBuyCsv}'
                INTO TABLE {$this->reasonsToBuyTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, number, value, title, pic)"
        );
        $this->endTimingStep();
    }

    public function apply(): void
    {
        $catalog_product_entity_text = $this->getTableName('catalog_product_entity_text');
        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');

        $reasonsToBuyAttr = $this->dataHelper->getProductAttributeId('sinch_reasons_to_buy');

        $conn = $this->getConnection();
        //Insert global values for Reasons to Buy
        $this->startTimingStep('Insert Reasons to Buy values');
        //Fetch all product entity IDs which have values for reasons to buy
        $ids = $conn->fetchCol(
            "SELECT DISTINCT spm.entity_id
                    FROM {$sinch_products_mapping} spm
                    INNER JOIN {$this->reasonsToBuyTable} srtb
                        ON spm.sinch_product_id = srtb.id
                    WHERE srtb.value IS NOT NULL"
        );
        //Now select the values for each product and JSON encode them for storage in the attribute
        foreach ($ids as $productEntityId) {
            $prodReasonsToBuy = $conn->fetchAll(
                "SELECT srtb.title, srtb.pic, srtb.value
                        FROM {$this->reasonsToBuyTable} srtb
                        INNER JOIN {$sinch_products_mapping} spm
                            ON srtb.id = spm.sinch_product_id
                        WHERE spm.entity_id = :entityId
                        ORDER BY srtb.number",
                [':entityId' => $productEntityId]
            );
            //Is inserting row by row too slow? (seems to be fast enough, but we should keep an eye on this)
            $conn->query(
                "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value)
                        VALUES (:reasonsToBuyAttr, 0, :entityId, :reasons) ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [
                    ':reasonsToBuyAttr' => $reasonsToBuyAttr,
                    ':entityId' => $productEntityId,
                    ':reasons' => json_encode($prodReasonsToBuy)
                ]
            );
        }
        //Now clear the reasons to buy for any other products we haven't seen in the reasons to buy table
        $conn->query(
            "UPDATE {$catalog_product_entity_text}
                    SET value = NULL
                    WHERE entity_id NOT IN (
                        SELECT DISTINCT spm.entity_id
                            FROM {$this->reasonsToBuyTable} srtb
                            INNER JOIN {$sinch_products_mapping} spm
                                ON srtb.id = spm.sinch_product_id
                    )"
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    private function createTableIfRequired(): void
    {
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->reasonsToBuyTable} (
                id int(10) unsigned NOT NULL COMMENT 'Reasons to Buy ID',
                number int(10) unsigned NOT NULL COMMENT 'Reason Number',
                value text,
                PRIMARY KEY (id, number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );

        if (!$this->getConnection()->tableColumnExists($this->reasonsToBuyTable, "title")) {
            $this->getConnection()->query(
                "ALTER TABLE {$this->reasonsToBuyTable} ADD COLUMN title varchar(255) NOT NULL DEFAULT '' AFTER value"
            );
        }

        if (!$this->getConnection()->tableColumnExists($this->reasonsToBuyTable, "pic")) {
            $this->getConnection()->query(
                "ALTER TABLE {$this->reasonsToBuyTable} ADD COLUMN pic varchar(255) NOT NULL DEFAULT '' AFTER title"
            );
        }
    }
}