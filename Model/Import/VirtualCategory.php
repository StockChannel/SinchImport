<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class VirtualCategory applies the sinch_virtual_category attribute to categories
 * @package SITC\Sinchimport\Model\Import
 */
class VirtualCategory extends AbstractImportSection {
    const LOG_PREFIX = "VirtualCategory: ";
    const LOG_FILENAME = "virtual_category";

    private Data $dataHelper;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;
    }

    public function parse(): void
    {
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');
        $eav_attribute_option = $this->getTableName('eav_attribute_option');
        $eav_attribute_option_value = $this->getTableName('eav_attribute_option_value');
        $sinch_categories = $this->getTableName('sinch_categories');
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');

        $virtualCatAttr = $this->dataHelper->getCategoryAttributeId('sinch_virtual_category');

        $conn = $this->getConnection();

        if (!$conn->tableColumnExists($sinch_categories, "VirtualCategory")) {
            $this->logger->warning("VirtualCategory column doesn't exist on sinch_categories yet");
            $this->logger->warning("Assuming this is the first import after Nile upgrade and skipping section...");
            return;
        }

        $this->startTimingStep('Delete removed virtual category values');
        $conn->query(
            "DELETE eao, eaov FROM $eav_attribute_option eao
                    INNER JOIN $eav_attribute_option_value eaov
                        ON eao.option_id = eaov.option_id
                    WHERE eao.attribute_id = :virtualCat
                        AND eaov.value NOT IN (SELECT DISTINCT VirtualCategory FROM $sinch_categories)",
            [":virtualCat" => $virtualCatAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Create missing virtual category values');
        $missingCats = $conn->fetchCol(
            "SELECT DISTINCT VirtualCategory FROM $sinch_categories
                    WHERE VirtualCategory NOT IN (
                        SELECT eaov.value FROM $eav_attribute_option_value eaov
                            INNER JOIN $eav_attribute_option eao
                                ON eaov.option_id = eao.option_id
                            WHERE attribute_id = :virtualCat
                    )",
            [":virtualCat" => $virtualCatAttr]
        );
        foreach ($missingCats as $missingCat) {
            if (empty($missingCat)) {
                continue;
            }
            $conn->query(
                "INSERT INTO $eav_attribute_option (attribute_id) VALUES(:virtualCat)",
                [":virtualCat" => $virtualCatAttr]
            );
            $conn->query(
                "INSERT INTO $eav_attribute_option_value (option_id, store_id, value) VALUES(LAST_INSERT_ID(), 0, :value)",
                [":value" => $missingCat]
            );
        }
        $this->endTimingStep();

        $this->startTimingStep('Insert Virtual Category values');
        $this->getConnection()->query(
            "INSERT INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value) (
                SELECT :virtualCat, 0, cce.entity_id, vcm.option_id
                FROM $catalog_category_entity cce
                INNER JOIN $sinch_categories_mapping scm
                    ON cce.entity_id = scm.shop_entity_id
                INNER JOIN $sinch_categories sc
                    ON scm.store_category_id = sc.store_category_id
                LEFT JOIN (
                    SELECT eao.option_id, eaov.value FROM $eav_attribute_option eao
                        INNER JOIN $eav_attribute_option_value eaov
                            ON eao.option_id = eaov.option_id
                        WHERE attribute_id = :virtualCat
                    ) vcm
                    ON sc.VirtualCategory = vcm.value
            )
            ON DUPLICATE KEY UPDATE
                value = vcm.option_id",
            [":virtualCat" => $virtualCatAttr]
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    public function getRequiredFiles(): array
    {
        //We don't directly need any files
        return [];
    }
}