<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class ProductDates applies Product Release and EOL Dates
 * @package SITC\Sinchimport\Model\Import
 */
class ProductDates extends AbstractImportSection {
    const LOG_PREFIX = "ProductDates: ";
    const LOG_FILENAME = "product_dates";

    private $dataHelper;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;
    }

    public function parse()
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_datetime = $this->getTableName('catalog_product_entity_datetime');
        $sinch_products = $this->getTableName('sinch_products');

        $releaseDateAttr = $this->dataHelper->getProductAttributeId('sinch_release_date');
        $eolDateAttr = $this->dataHelper->getProductAttributeId('sinch_eol_date');

        //Insert global values for Release Date
        $this->startTimingStep('Insert Release Date values');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_datetime} (attribute_id, store_id, entity_id, value) (
                SELECT :releaseDate, 0, cpe.entity_id, sp.release_date
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":releaseDate" => $releaseDateAttr]
        );
        $this->endTimingStep();

        //Insert global values for EOL Date
        $this->startTimingStep('Insert EOL Date values');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_datetime} (attribute_id, store_id, entity_id, value) (
                SELECT :eolDate, 0, cpe.entity_id, sp.eol_date
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":eolDate" => $eolDateAttr]
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