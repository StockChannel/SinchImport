<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class RelatedProducts extends AbstractImportSection
{
    const LOG_PREFIX = "RelatedProducts: ";
    const LOG_FILENAME = "related_products";

    private string $relatedProductsTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
        $this->relatedProductsTable = $this->getTableName('sinch_related_products');
    }

    public function parse()
    {
        $relatedProductsCsv = $this->dlHelper->getSavePath(Download::FILE_RELATED_PRODUCTS);

        $catalog_product_entity = $this->getTableName('catalog_product_entity');

        $this->log("Start parsing " . Download::FILE_RELATED_PRODUCTS);
        $conn = $this->getConnection();

        $this->startTimingStep('Recreate import table');
        $conn->query("DROP TABLE IF EXISTS {$this->relatedProductsTable}");
        //The foreign key constraints are to ensure that if entity_id and related_entity_id have been populated, they reference valid values
        $conn->query(
            "CREATE TABLE IF NOT EXISTS {$this->relatedProductsTable} (
                     sinch_product_id int(11) NOT NULL,
                     related_sinch_product_id int(11) NOT NULL,
                     entity_id int(11),
                     related_entity_id int(11),
                     position int(11) NOT NULL DEFAULT 0,
                     KEY(sinch_product_id),
                     KEY(related_sinch_product_id),
                     UNIQUE KEY(sinch_product_id, related_sinch_product_id),
                     UNIQUE KEY(entity_id, related_entity_id),
                     FOREIGN KEY(entity_id) REFERENCES $catalog_product_entity (entity_id) ON DELETE CASCADE,
                     FOREIGN KEY(related_entity_id) REFERENCES $catalog_product_entity (entity_id) ON DELETE CASCADE
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
        $this->endTimingStep();

        $this->startTimingStep('Load data');
        $conn->query(
            "LOAD DATA LOCAL INFILE :relatedProductsCsv
                      INTO TABLE {$this->relatedProductsTable}
                      FIELDS TERMINATED BY '|'
                      OPTIONALLY ENCLOSED BY '\"'
                      LINES TERMINATED BY \"\r\n\"
                      IGNORE 1 LINES
                      (sinch_product_id, related_sinch_product_id)",
            [":relatedProductsCsv" => $relatedProductsCsv]
        );
        $this->endTimingStep();

        $this->log("Finish parsing " . Download::FILE_RELATED_PRODUCTS);
    }

    public function apply()
    {
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_link = $this->getTableName('catalog_product_link');
        $catalog_product_link_type = $this->getTableName('catalog_product_link_type');
        $catalog_product_link_attribute = $this->getTableName('catalog_product_link_attribute');
        $catalog_product_link_attribute_int = $this->getTableName('catalog_product_link_attribute_int');

        $conn = $this->getConnection();
        $linkType = $conn->fetchPairs("SELECT link_type_id, code FROM $catalog_product_link_type");

        //Update the entity id's for the products in sinch_related_products
        $this->startTimingStep('Map main products to Magento products');
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                      LEFT JOIN $catalog_product_entity cpe
                        ON srp.sinch_product_id = cpe.sinch_product_id
                      SET srp.entity_id = cpe.entity_id"
        );
        $this->endTimingStep();

        //Update the entity id's for the related products in sinch_related_products
        $this->startTimingStep('Map related products to Magento products');
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                      LEFT JOIN $catalog_product_entity cpe
                        ON srp.related_sinch_product_id = cpe.sinch_product_id
                      SET srp.related_entity_id = cpe.entity_id"
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert related product links');
        $conn->query(
            "INSERT INTO $catalog_product_link (product_id, linked_product_id, link_type_id) (
                SELECT srp.entity_id, srp.related_entity_id, :linkTypeId
                FROM {$this->relatedProductsTable} srp
                WHERE srp.entity_id IS NOT NULL
                    AND srp.related_entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                linked_product_id = VALUES(linked_product_id)",
            [":linkTypeId" => $linkType['relation']]
        );
        $this->endTimingStep();


//        $link_attribute_tmp = $this->getTableName('catalog_product_link_attribute_int_tmp');
//        $conn->query("DROP TABLE IF EXISTS $link_attribute_tmp");
//        $conn->query(
//            "CREATE TEMPORARY TABLE $link_attribute_tmp (
//                `value_id` int(11) default NULL,
//                `product_link_attribute_id` smallint(6) unsigned default NULL,
//                `link_id` int(11) unsigned default NULL,
//                `value` int(11) NOT NULL default '0',
//                    KEY `FK_INT_PRODUCT_LINK_ATTRIBUTE` (`product_link_attribute_id`),
//                    KEY `FK_INT_PRODUCT_LINK` (`link_id`)
//            )"
//        );

        //This insert below this used to use the magic value '2', which corresponds to the up_sell position attribute as far as I can tell on normal installations
        //However given the links are inserted as relation, and relation has its own position attribute, it was probably a bug, so we should probably use relation's position attribute instead
        $relationPosAttr = $conn->fetchOne(
            "SELECT product_link_attribute_id FROM $catalog_product_link_attribute WHERE link_type_id = :linkTypeId AND product_link_attribute_code = :code",
            [
                ":linkTypeId" => $linkType['relation'],
                ":code" => 'position'
            ]
        );

        //Experimental direct to table
        $conn->query(
            "INSERT INTO $catalog_product_link_attribute_int (product_link_attribute_id, link_id, value) (
                SELECT :linkAttr, cpl.link_id, srp.position FROM $catalog_product_link cpl
                    INNER JOIN {$this->relatedProductsTable} srp
                        ON cpl.product_id = srp.entity_id
                        AND cpl.linked_product_id = srp.related_entity_id
                    
            ) ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":linkAttr" => $relationPosAttr]
        );

//        $conn->query(
//            "INSERT INTO $link_attribute_tmp (product_link_attribute_id, link_id, value) (
//                SELECT :linkAttr, cpl.link_id, 0 FROM $catalog_product_link cpl
//            )",
//            [":linkAttr" => $relationPosAttr]
//        );
//
//        $conn->query(
//            "UPDATE $link_attribute_tmp ct
//                JOIN $link_attribute_int c
//                    ON ct.link_id = c.link_id
//                SET ct.value_id = c.value_id
//                WHERE c.product_link_attribute_id = :linkAttr",
//            [":linkAttr" => $relationPosAttr]
//        );
//
//        $conn->query(
//            "INSERT INTO $catalog_product_link_attribute_int (
//                value_id,
//                product_link_attribute_id,
//                link_id,
//                value
//            )(
//                SELECT
//                value_id,
//                product_link_attribute_id,
//                link_id,
//                value
//                FROM $link_attribute_tmp ct
//            )
//            ON DUPLICATE KEY UPDATE
//                link_id=ct.link_id"
//        );
        $this->timingPrint();
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_RELATED_PRODUCTS];
    }
}