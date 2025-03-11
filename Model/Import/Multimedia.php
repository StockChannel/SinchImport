<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class Multimedia extends AbstractImportSection {
    const LOG_PREFIX = "Multimedia: ";
    const LOG_FILENAME = "multimedia";

    /** @var Data */
    private $dataHelper;

    /** @var string */
    private $multimediaTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper, Data $dataHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->dataHelper = $dataHelper;

        $this->multimediaTable = $this->getTableName('sinch_multimedia');
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_MULTIMEDIA];
    }

    public function parse(): void
    {
        $this->createTableIfRequired();
        $multimediaCsv = $this->dlHelper->getSavePath(Download::FILE_MULTIMEDIA);

        $conn = $this->getConnection();

        $this->startTimingStep('Load Multimedia');
        $conn->query("DELETE FROM {$this->multimediaTable}");
        //ID|ProductID|Description|URL|ContentType
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$multimediaCsv}'
                INTO TABLE {$this->multimediaTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_id, sinch_product_id, description, url, content_type)"
        );
        $this->endTimingStep();

        // product PDF Url for all web sites
//        $this->_doQuery(
//            "UPDATE " . $this->getTableName('products_temp') . "
//                    SET pdf_url = CONCAT(
//                        '<a href=\"#\" onclick=\"popWin(',
//                        \"'\",
//                        pdf_url,
//                        \"'\",
//                        \", 'pdf', 'width=500,height=800,left=50,top=50, location=no,status=yes,scrollbars=yes,resizable=yes'); return false;\",
//                        '\"',
//                        '>',
//                        pdf_url,
//                        '</a>'
//                    )
//                    WHERE pdf_url != ''"
//        );

        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_text = $this->getTableName('catalog_product_entity_text');

//        $products_website_temp = $this->getTableName('products_website_temp');

        $pdfAttr = $this->dataHelper->getProductAttributeId('pdf_url');
        $videosAttr = $this->dataHelper->getProductAttributeId('sinch_videos');
        $manualsAttr = $this->dataHelper->getProductAttributeId('sinch_manuals');
        $additionalImgAttr = $this->dataHelper->getProductAttributeId('sinch_additional_images');

        $this->startTimingStep('PDF Urls');
        //Insert per website values
//        $conn->query(
//            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
//              SELECT :pdfAttr, pwt.website, cpe.entity_id, pt.pdf_url
//              FROM {$catalog_product_entity} cpe
//              INNER JOIN {$products_temp} pt
//                ON cpe.sinch_product_id = pt.sinch_product_id
//              INNER JOIN {$products_website_temp} pwt
//                ON cpe.sinch_product_id = pwt.sinch_product_id
//            )
//            ON DUPLICATE KEY UPDATE
//                value = pt.pdf_url",
//            [":pdfAttr" => $pdfAttr]
//        );

        //Insert global values
        //Ensure GROUP_CONCAT max length is long enough
        $conn->query("SET SESSION group_concat_max_len = 102400");

        //Concat all available PDF Urls for each product into the attribute, and put the onus on the frontend rendering to display it nicely
        $conn->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                    SELECT :pdfAttr, 0, cpe.entity_id, GROUP_CONCAT(DISTINCT smm.url SEPARATOR ';')
                    FROM {$this->multimediaTable} smm
                    INNER JOIN {$catalog_product_entity} cpe
                        ON smm.sinch_product_id = cpe.sinch_product_id
                    WHERE smm.content_type = 'application/pdf'
                    GROUP BY cpe.entity_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":pdfAttr" => $pdfAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Product Videos');
        //We assume that all video types can be handled on the frontend
        $conn->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                    SELECT :videosAttr, 0, cpe.entity_id, GROUP_CONCAT(DISTINCT smm.url SEPARATOR ';')
                    FROM {$this->multimediaTable} smm
                    INNER JOIN {$catalog_product_entity} cpe
                        ON smm.sinch_product_id = cpe.sinch_product_id
                    WHERE smm.content_type LIKE 'video/%'
                    GROUP BY cpe.entity_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":videosAttr" => $videosAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Product Manuals');
        //Realistically these are just the same as PDF Urls, but HTML
        $conn->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                    SELECT :manualsAttr, 0, cpe.entity_id, GROUP_CONCAT(DISTINCT smm.url SEPARATOR ';')
                    FROM {$this->multimediaTable} smm
                    INNER JOIN {$catalog_product_entity} cpe
                        ON smm.sinch_product_id = cpe.sinch_product_id
                    WHERE smm.content_type = 'text/html'
                    GROUP BY cpe.entity_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":manualsAttr" => $manualsAttr]
        );
        $this->endTimingStep();

        $this->startTimingStep('Product Additional Images');
        //We assume that all image types can be handled on the frontend
        $conn->query(
            "INSERT INTO {$catalog_product_entity_text} (attribute_id, store_id, entity_id, value) (
                    SELECT :additionalImg, 0, cpe.entity_id, GROUP_CONCAT(DISTINCT smm.url SEPARATOR ';')
                    FROM {$this->multimediaTable} smm
                    INNER JOIN {$catalog_product_entity} cpe
                        ON smm.sinch_product_id = cpe.sinch_product_id
                    WHERE smm.content_type LIKE 'image/%'
                    GROUP BY cpe.entity_id
            )
            ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":additionalImg" => $additionalImgAttr]
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    private function createTableIfRequired()
    {
        //Ideally this would have a foreign key to sinch_products, but the way we currently handle that table precludes it
        // and would likely slow down the load data anyway
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->multimediaTable} (
                sinch_id int(10) unsigned NOT NULL PRIMARY KEY,
                sinch_product_id int(10) unsigned NOT NULL COMMENT 'Sinch Product ID',
                description varchar(255),
                url varchar(255),
                content_type varchar(128) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}