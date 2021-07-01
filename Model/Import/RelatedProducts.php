<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class RelatedProducts extends AbstractImportSection
{
    const LOG_PREFIX = "RelatedProducts: ";
    const LOG_FILENAME = "related_products";

    const FAMILY_BOOST = 10;
    const SERIES_BOOST = 20;
    const PROFIT_BOOST = 5;
    const POPULARITY_BOOST_MAX = 5;
    const MONTHLY_SALES_BOOST_MAX = 4;
    const YEARLY_SALES_BOOST_MAX = 3;

    const UPSELL_CHEAPER_ABOVE = 0.8;
    const UPSELL_CHEAPER_MIN_RELATIVE_PROFIT = 0.95;
    const UPSELL_DEARER_BELOW = 1.5;
    const UPSELL_DEARER_MIN_RELATIVE_PROFIT = 0.9;

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
                     link_type varchar(32) NOT NULL DEFAULT 'relation',
                     entity_id int(11) unsigned,
                     related_entity_id int(11) unsigned,
                     position int(11) NOT NULL DEFAULT 0,
                     KEY(sinch_product_id),
                     KEY(related_sinch_product_id),
                     UNIQUE KEY(sinch_product_id, related_sinch_product_id, link_type),
                     UNIQUE KEY(entity_id, related_entity_id, link_type),
                     FOREIGN KEY(entity_id) REFERENCES $catalog_product_entity (entity_id) ON DELETE CASCADE,
                     FOREIGN KEY(related_entity_id) REFERENCES $catalog_product_entity (entity_id) ON DELETE CASCADE
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
        $this->endTimingStep();

        $this->startTimingStep('Load data');
        //Currently we insert the related products with the link type "unused", so they won't be mapped by the rest of this process.
        // This is primarily as the related products provided by icecat suck, and we intend to override the set ourselves
        $conn->query(
            "LOAD DATA LOCAL INFILE :relatedProductsCsv
                      INTO TABLE {$this->relatedProductsTable}
                      FIELDS TERMINATED BY '|'
                      OPTIONALLY ENCLOSED BY '\"'
                      LINES TERMINATED BY \"\r\n\"
                      IGNORE 1 LINES
                      (sinch_product_id, related_sinch_product_id)
                      SET link_type = 'unused'",
            [":relatedProductsCsv" => $relatedProductsCsv]
        );
        $this->endTimingStep();

        $this->log("Finish parsing " . Download::FILE_RELATED_PRODUCTS);
    }

    public function apply()
    {
        $sinch_product_categories = $this->getTableName('sinch_product_categories');
        $sinch_categories = $this->getTableName('sinch_categories');
        $sinch_stock_and_prices = $this->getTableName('sinch_stock_and_prices');
        $sinch_products = $this->getTableName('sinch_products');
        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');

        $catalog_product_link = $this->getTableName('catalog_product_link');
        $catalog_product_link_type = $this->getTableName('catalog_product_link_type');
        $catalog_product_link_attribute = $this->getTableName('catalog_product_link_attribute');
        $catalog_product_link_attribute_int = $this->getTableName('catalog_product_link_attribute_int');

        $conn = $this->getConnection();

        $this->startTimingStep('Generate upsell products');
        //Join every product to every other product in every category it's in under the following conditions:
        //  - The category they share has 0 < products < 10k and is a leaf
        //  - Neither the main nor reference product can have a public price of 0 (it would indicate a private product)
        //  - Both products are currently in stock
        //  - Products which share more than 1 category have their position increased by 1 for each additional category (not really a condition but it is something this query does)
        //  - The reference product public price must be dearer than UPSELL_CHEAPER_ABOVE * the main products public price
        //  - The reference product public price must be cheaper than UPSELL_DEARER_BELOW * the main products public price
        //  - If the reference product is cheaper than the main product, it must have a profit ratio at least
        //      UPSELL_CHEAPER_MIN_RELATIVE_PROFIT * the main product profit ratio
        //  - If the reference product is dearer than the main product, it must have a profit ratio at least
        //      UPSELL_DEARER_MIN_RELATIVE_PROFIT * the main product profit ratio
        $conn->query(
            "INSERT INTO {$this->relatedProductsTable} (sinch_product_id, related_sinch_product_id, link_type) (
                SELECT spc.store_product_id, spc2.store_product_id, 'up_sell' FROM $sinch_product_categories spc
                    INNER JOIN $sinch_product_categories spc2
                        ON spc.store_category_id = spc2.store_category_id
                        AND spc.store_product_id != spc2.store_product_id
                    INNER JOIN $sinch_categories sc
                        ON spc.store_category_id = sc.store_category_id
                    INNER JOIN $sinch_stock_and_prices ssp_main
                        ON spc.store_product_id = ssp_main.product_id
                    INNER JOIN $sinch_stock_and_prices ssp_ref
                        ON spc2.store_product_id = ssp_ref.product_id
                    WHERE ssp_main.stock > 0
                        AND ssp_main.price != 0
                        AND ssp_ref.stock > 0
                        AND ssp_ref.price != 0
                        AND (
                            (
                                ssp_ref.price >= ssp_main.price * :upsellCheaperAbove AND ssp_ref.price < ssp_main.price
                                AND (
                                    ssp_ref.cost IS NULL
                                    OR ssp_main.cost IS NULL
                                    OR (
                                        ssp_ref.cost IS NOT NULL
                                        AND ssp_main.cost IS NOT NULL
                                        AND ssp_ref.price / ssp_ref.cost >= (ssp_main.price / ssp_main.cost) * :upsellCheaperMinRelativeProfit
                                    )
                                )
                            )
                            OR (
                                ssp_ref.price >= ssp_main.price AND ssp_ref.price < ssp_main.price * :upsellDearerBelow
                                AND (
                                    ssp_ref.cost IS NULL
                                    OR ssp_main.cost IS NULL
                                    OR (
                                        ssp_ref.cost IS NOT NULL
                                        AND ssp_main.cost IS NOT NULL
                                        AND ssp_ref.price / ssp_ref.cost >= (ssp_main.price / ssp_ref.cost) * :upsellDearerMinRelativeProfit
                                    )
                                )
                            )
                        )
                        AND sc.children_count = 0
                        AND sc.products_within_this_category > 0
                        AND sc.products_within_this_category < 10000
            ) ON DUPLICATE KEY UPDATE
                position = position + 1",
            [
                ":upsellCheaperAbove" => self::UPSELL_CHEAPER_ABOVE,
                ":upsellCheaperMinRelativeProfit" => self::UPSELL_CHEAPER_MIN_RELATIVE_PROFIT,
                ":upsellDearerBelow" => self::UPSELL_DEARER_BELOW,
                ":upsellDearerMinRelativeProfit" => self::UPSELL_DEARER_MIN_RELATIVE_PROFIT
            ]
        );
        $this->endTimingStep();

        $this->startTimingStep('Generate related products');
        //The main determining factor for speed here is the family_id != 0 (as otherwise all products without family match all other products without family)
        $conn->query(
            "INSERT INTO {$this->relatedProductsTable} (sinch_product_id, related_sinch_product_id, link_type) (
                SELECT spc.store_product_id, spc2.store_product_id, 'relation' FROM $sinch_product_categories spc
                    INNER JOIN $sinch_product_categories spc2    
                        ON spc.store_product_id != spc2.store_product_id
                        AND spc.store_category_id NOT IN (
                            SELECT spc_sub.store_category_id FROM $sinch_product_categories spc_sub
                            WHERE spc_sub.store_product_id = spc2.store_product_id
                        )
                    INNER JOIN $sinch_categories sc2
                        ON spc2.store_category_id = sc2.store_category_id
                    INNER JOIN $sinch_products sp
                        ON spc.store_product_id = sp.sinch_product_id
                    INNER JOIN $sinch_products sp2
                        ON spc2.store_product_id = sp2.sinch_product_id
                    INNER JOIN $sinch_stock_and_prices ssp_main
                        ON spc.store_product_id = ssp_main.product_id
                    INNER JOIN $sinch_stock_and_prices ssp_ref
                        ON spc2.store_product_id = ssp_ref.product_id
                    WHERE ssp_main.stock > 0
                        AND ssp_main.price != 0
                        AND ssp_ref.stock > 0
                        AND ssp_ref.price != 0
                        AND sc2.children_count = 0
                        AND sc2.products_within_this_category < 10000
                        AND sp.family_id != 0
                        AND sp.family_id = sp2.family_id
                        AND sp.series_id = sp2.series_id
            ) ON DUPLICATE KEY UPDATE
                position = position + 1"
        );
        $this->endTimingStep();

        //Update the entity id's for the products in sinch_related_products
        $this->startTimingStep('Map main products to Magento products');
        //The previous iteration of this was far too slow (likely due to the use of sinch_product_id on cpe, an unindexed field)
        //So now we'll just use the mapping table (where it is indexed)
        //Making sure to explicitly compare with null as srp.entity_id != spm.entity_id doesn't include any rows where one side is null
        //https://dev.mysql.com/doc/refman/8.0/en/working-with-null.html
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                      INNER JOIN $sinch_products_mapping spm
                        ON srp.sinch_product_id = spm.sinch_product_id
                      SET srp.entity_id = spm.entity_id
                      WHERE srp.entity_id != spm.entity_id
                        OR (srp.entity_id IS NULL XOR spm.entity_id IS NULL)"
        );
        $this->endTimingStep();

        //Update the entity id's for the related products in sinch_related_products
        $this->startTimingStep('Map related products to Magento products');
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                      INNER JOIN $sinch_products_mapping spm
                        ON srp.related_sinch_product_id = spm.sinch_product_id
                      SET srp.related_entity_id = spm.entity_id
                      WHERE srp.related_entity_id != spm.entity_id
                        OR (srp.related_entity_id IS NULL XOR spm.entity_id IS NULL)"
        );
        $this->endTimingStep();

        $this->startTimingStep('Insert related product links');
        $conn->query(
            "INSERT INTO $catalog_product_link (product_id, linked_product_id, link_type_id) (
                SELECT srp.entity_id, srp.related_entity_id, link_type.link_type_id
                FROM {$this->relatedProductsTable} srp
                INNER JOIN $catalog_product_link_type link_type
                    ON srp.link_type = link_type.code
                WHERE srp.entity_id IS NOT NULL
                    AND srp.related_entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                linked_product_id = VALUES(linked_product_id),
                link_type_id = VALUES(link_type_id)"
        );
        $this->endTimingStep();

        $this->startTimingStep('Increase relation positions based on popularity');
        //Increase the position of relationships based on popularity score
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                    INNER JOIN $sinch_products sp
                        ON srp.related_sinch_product_id = sp.sinch_product_id
                    INNER JOIN (
                        SELECT srp2.sinch_product_id, MIN(sp2.score) AS min, MAX(sp2.score) AS max FROM {$this->relatedProductsTable} srp2
                            INNER JOIN $sinch_products sp2
                                ON srp2.related_sinch_product_id = sp2.sinch_product_id
                        WHERE sp2.score != 0
                        GROUP BY srp2.sinch_product_id
                    ) scores
                        ON srp.sinch_product_id = scores.sinch_product_id
                    SET position = position + (((sp.score - scores.min) * :popularityBoostMax) / (scores.max - scores.min))
                    WHERE sp.score > scores.min",
            [":popularityBoostMax" => self::POPULARITY_BOOST_MAX]
        );

        //Increase the position of relationships based on monthly implied sales
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                    INNER JOIN $sinch_products sp
                        ON srp.related_sinch_product_id = sp.sinch_product_id
                    INNER JOIN (
                        SELECT srp2.sinch_product_id, MIN(sp2.implied_sales_month) AS min, MAX(sp2.implied_sales_month) AS max FROM {$this->relatedProductsTable} srp2
                            INNER JOIN $sinch_products sp2
                                ON srp2.related_sinch_product_id = sp2.sinch_product_id
                        WHERE sp2.implied_sales_month != 0
                        GROUP BY srp2.sinch_product_id
                    ) implied_sales
                        ON srp.sinch_product_id = implied_sales.sinch_product_id
                    SET position = position + (((sp.implied_sales_month - implied_sales.min) * :monthlySalesBoostMax) / (implied_sales.max - implied_sales.min))
                    WHERE sp.implied_sales_month > implied_sales.min",
            [":monthlySalesBoostMax" => self::MONTHLY_SALES_BOOST_MAX]
        );

        //Increase the position of relationships based on yearly implied sales
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                    INNER JOIN $sinch_products sp
                        ON srp.related_sinch_product_id = sp.sinch_product_id
                    INNER JOIN (
                        SELECT srp2.sinch_product_id, MIN(sp2.implied_sales_year) AS min, MAX(sp2.implied_sales_year) AS max FROM {$this->relatedProductsTable} srp2
                            INNER JOIN $sinch_products sp2
                                ON srp2.related_sinch_product_id = sp2.sinch_product_id
                        WHERE sp2.implied_sales_year != 0
                        GROUP BY srp2.sinch_product_id
                    ) implied_sales
                        ON srp.sinch_product_id = implied_sales.sinch_product_id
                    SET position = position + (((sp.implied_sales_year - implied_sales.min) * :yearlySalesBoostMax) / (implied_sales.max - implied_sales.min))
                    WHERE sp.implied_sales_year > implied_sales.min",
            [":yearlySalesBoostMax" => self::YEARLY_SALES_BOOST_MAX]
        );
        $this->endTimingStep();

        $this->startTimingStep('Increase relation positions based on price/cost ratio');
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                    INNER JOIN $sinch_stock_and_prices ssp_main
                        ON srp.sinch_product_id = ssp_main.product_id
                    INNER JOIN $sinch_stock_and_prices ssp_ref
                        ON srp.related_sinch_product_id = ssp_ref.product_id
                    SET position = position + :profitBoost
                    WHERE ssp_main.cost IS NOT NULL
                      AND ssp_ref.cost IS NOT NULL
                      AND ssp_ref.price / ssp_ref.cost > ssp_main.price / ssp_main.cost",
            [":profitBoost" => self::PROFIT_BOOST]
        );
        $this->endTimingStep();

        $this->startTimingStep('Increase relation positions based on Product Family/Series');
        //Increase the position of relationships between products in the same family
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                    INNER JOIN $sinch_products sp1
                        ON srp.sinch_product_id = sp1.sinch_product_id
                    INNER JOIN $sinch_products sp2
                        ON srp.related_sinch_product_id = sp2.sinch_product_id
                    SET position = position + :familyBoost
                    WHERE sp1.family_id = sp2.family_id
                        AND sp1.family_id IS NOT NULL",
            [":familyBoost" => self::FAMILY_BOOST]
        );

        //Further increase the position of relationships between products in the same family and family series
        $conn->query(
            "UPDATE {$this->relatedProductsTable} srp
                    INNER JOIN $sinch_products sp1
                        ON srp.sinch_product_id = sp1.sinch_product_id
                    INNER JOIN $sinch_products sp2
                        ON srp.related_sinch_product_id = sp2.sinch_product_id
                    SET position = position + :seriesBoost
                    WHERE sp1.family_id = sp2.family_id
                        AND sp1.series_id = sp2.series_id
                        AND sp1.family_id IS NOT NULL
                        AND sp1.series_id IS NOT NULL",
            [":seriesBoost" => self::SERIES_BOOST]
        );
        $this->endTimingStep();

        $this->startTimingStep('Update relation position/ordering');
        //This insert used to use the magic value '2', which corresponds to the up_sell position attribute as far as I can tell on normal installations
        //However given the links were inserted as relation, and relation has its own position attribute, it was probably a bug, so we should probably use the correct position attribute instead
        //The previous iteration of this query took a long time (over an hour on the sandbox test feed). In my manual testing, this version runs between 50 and 100% faster than the previous iteration
        // For some reason it also seems to produce ~6k less rows (5,564,676 vs 5,571,386), but I think this one should be correct
        $conn->query(
            "INSERT INTO $catalog_product_link_attribute_int (product_link_attribute_id, link_id, value) (
                SELECT link_attr.product_link_attribute_id, cpl.link_id, srp.position FROM catalog_product_link cpl
                    INNER JOIN $catalog_product_link_type link_type
                        ON cpl.link_type_id = link_type.link_type_id
                    INNER JOIN {$this->relatedProductsTable} srp
                        ON cpl.product_id = srp.entity_id
                        AND cpl.linked_product_id = srp.related_entity_id
                        AND srp.link_type = link_type.code
                    INNER JOIN $catalog_product_link_attribute link_attr
                        ON link_type.link_type_id = link_attr.link_type_id
                        AND link_attr.product_link_attribute_code = :linkAttrCode
            ) ON DUPLICATE KEY UPDATE
                value = VALUES(value)",
            [":linkAttrCode" => 'position']
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_RELATED_PRODUCTS];
    }
}