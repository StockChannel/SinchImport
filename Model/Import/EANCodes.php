<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class EANCodes extends AbstractImportSection {
    const LOG_PREFIX = "EANCodes: ";
    const LOG_FILENAME = "ean_codes";

    private Data $dataHelper;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;
    }

    public function parse(): void
    {
        $eanAttr = $this->dataHelper->getProductAttributeId('ean');

        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_varchar = $this->getTableName('catalog_product_entity_varchar');
        $products_temp = $this->getTableName('products_temp');
        $products_website_temp = $this->getTableName('products_website_temp');

        $conn = $this->getConnection();

        $this->startTimingStep('Product EAN (global)');
        $conn->query(
            "INSERT INTO {$catalog_product_entity_varchar} (attribute_id, store_id, entity_id, value) (
                SELECT :eanAttr, 0, cpe.entity_id, pt.ean_code
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$products_temp} pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.ean_code",
            [":eanAttr" => $eanAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Product EAN (website)');
        $conn->query(
            "INSERT INTO {$catalog_product_entity_varchar} (attribute_id, store_id, entity_id, value) (
                SELECT :eanAttr, pwt.website, cpe.entity_id, pt.ean_code
                FROM {$catalog_product_entity} cpe
                INNER JOIN {$products_temp} pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
                INNER JOIN {$products_website_temp} pwt
                    ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.ean_code",
            [":eanAttr" => $eanAttr]
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    public function getRequiredFiles(): array
    {
        //Requires no files as the data is contained within Products.csv as of the nile format
        return [];
    }
}