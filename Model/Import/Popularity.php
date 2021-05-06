<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class Popularity applies Product Popularity Score
 * @package SITC\Sinchimport\Model\Import
 */
class Popularity extends AbstractImportSection {
    const LOG_PREFIX = "Popularity: ";
    const LOG_FILENAME = "popularity";

    private $dataHelper;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;
    }

    public function parse()
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $sinch_products = $this->getTableName('sinch_products');

        $scoreAttr = $this->dataHelper->getProductAttributeId('sinch_score');

        //Insert global values for Popularity Score
        $this->startTimingStep('Insert Popularity Score values');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) (
                SELECT :scoreAttr, 0, cpe.entity_id, sp.score
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":scoreAttr" => $scoreAttr]
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