<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class Popularity applies Product Popularity Score as well as the BI data (implied monthly and yearly sales)
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

    public function parse(): void
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $sinch_products = $this->getTableName('sinch_products');

        $scoreAttr = $this->dataHelper->getProductAttributeId('sinch_score');
        $impliedSalesMonth = $this->dataHelper->getProductAttributeId('sinch_popularity_month');
        $impliedSalesYear = $this->dataHelper->getProductAttributeId('sinch_popularity_year');
        $searches = $this->dataHelper->getProductAttributeId('sinch_searches');

        //Insert global values for Popularity Score
        $this->startTimingStep('Insert Popularity Score values');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value)
            SELECT attribute_id, store_id, entity_id, value FROM
            (
                SELECT :scoreAttr as attribute_id, 0 as store_id, cpe.entity_id, sp.score as value
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            ) new_data
            ON DUPLICATE KEY UPDATE
                value = new_data.value",
            [":scoreAttr" => $scoreAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert Implied Sales values (1m)');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value) 
            SELECT attribute_id, store_id, entity_id, value FROM
            (
                SELECT :impliedMonth as attribute_id, 0 as store_id, cpe.entity_id, sp.implied_sales_month as value
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            ) new_data
            ON DUPLICATE KEY UPDATE
                value = new_data.value",
            [":impliedMonth" => $impliedSalesMonth]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert Implied Sales values (1y)');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value)
            SELECT attribute_id, store_id, entity_id, value FROM
            (
                SELECT :impliedYear as attribute_id, 0 as store_id, cpe.entity_id, sp.implied_sales_year as value
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            ) new_data
            ON DUPLICATE KEY UPDATE
                value = new_data.value",
            [":impliedYear" => $impliedSalesYear]
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert Sinch Searches');
        $this->getConnection()->query(
            "INSERT INTO {$catalog_product_entity_int} (attribute_id, store_id, entity_id, value)
            SELECT attribute_id, store_id, entity_id, value FROM
            (
                SELECT :searches as attribute_id, 0 as store_id, cpe.entity_id, sp.searches as value
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$sinch_products} sp
                    ON cpe.sinch_product_id = sp.sinch_product_id
            ) new_data
            ON DUPLICATE KEY UPDATE
                value = new_data.value",
            [":searches" => $searches]
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