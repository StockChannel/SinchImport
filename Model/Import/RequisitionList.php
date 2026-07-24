<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\SerializerInterface;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class RequisitionList extends AbstractImportSection
{
    const LOG_PREFIX = "RequisitionList: ";
    const LOG_FILENAME = "requisitionlist";

    private string $requisitionListTable;
    private string $requisitionProductTable;
    private string $requisitionBundleTable;
    private string $requisitionBundleItemsTable;
    private string $requisitionListMappingTable;
    private string $requisitionProductMappingTable;
    private string $requisitionBundleMappingTable;
    private string $requisitionBundleItemsMappingTable;

    public function __construct(
        ResourceConnection    $resourceConn,
        ConsoleOutput         $output,
        Download              $downloadHelper,
        private readonly Data $dataHelper,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly Json $jsonSerializer,
    )
    {
        parent::__construct($resourceConn, $output, $downloadHelper);

        $this->requisitionListTable = $this->getTableName('sinch_import_requisition_list');
        $this->requisitionProductTable = $this->getTableName('sinch_import_requisition_products');
        $this->requisitionBundleTable = $this->getTableName('sinch_import_requisition_bundles');
        $this->requisitionBundleItemsTable = $this->getTableName('sinch_import_requisition_bundle_items');
        $this->requisitionListMappingTable = $this->getTableName('sinch_import_requisition_list_mapping');
        $this->requisitionProductMappingTable = $this->getTableName('sinch_import_requisition_product_mapping');
        $this->requisitionBundleMappingTable = $this->getTableName('sinch_import_requisition_bundle_mapping');
        $this->requisitionBundleItemsMappingTable = $this->getTableName('sinch_import_requisition_bundle_item_mapping');
    }

    public function parse(): void
    {
        $this->createTablesIfRequired();

        $requisitionFile = $this->dlHelper->getSavePath(Download::FILE_REQUISITION_LIST);
        $requisitionProdsFile = $this->dlHelper->getSavePath(Download::FILE_REQUISITION_LIST_PRODUCTS);
        $requisitionBundleFile = $this->dlHelper->getSavePath(Download::FILE_REQUISITION_LIST_BUNDLES);
        $requisitionBundleOptionsFile = $this->dlHelper->getSavePath(Download::FILE_REQUISITION_LIST_BUNDLE_ITEMS_PRODUCTS);

        $this->startTimingStep('Clear import tables');
        $this->getConnection()->query("DELETE FROM {$this->requisitionListTable}");
        $this->getConnection()->query("DELETE FROM {$this->requisitionProductTable}");
        $this->getConnection()->query("DELETE FROM {$this->requisitionBundleTable}");
        $this->getConnection()->query("DELETE FROM {$this->requisitionBundleItemsTable}");
        $this->endTimingStep();

        $this->startTimingStep('Load Data');
        // Requisition lists themselves
        // ID|Name|Description
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$requisitionFile}'
                INTO TABLE {$this->requisitionListTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_requisition_id, name, description)"
        );

        // Requisition Products
        // ID|CoreListID|ProductID|Quantity|DaysInRequisition
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$requisitionProdsFile}'
                INTO TABLE {$this->requisitionProductTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_rq_product_id, sinch_requisition_id, sinch_product_id, quantity, days_in_requisition)"
        );

        // Requisition Bundles
        // ID|CoreListID|BundleID|Quantity|DaysInRequisition
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$requisitionBundleFile}'
                INTO TABLE {$this->requisitionBundleTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_rq_bundle_id, sinch_requisition_id, sinch_bundle_id, quantity, days_in_requisition)"
        );

        // Requisition Bundle Options
        // CoreListBundleID|BundleItemID|ProductID
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$requisitionBundleOptionsFile}'
                INTO TABLE {$this->requisitionBundleItemsTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_rq_bundle_id, sinch_bundle_item_id, sinch_product_id)"
        );
        $this->endTimingStep();

        // Begin main processing
        $requisitionTable = $this->getTableName('requisition');
        $requisitionItemTable = $this->getTableName('requisition_item');
        $requisitionItemOptionTable = $this->getTableName('requisition_item_option');
        $productMappingTable = $this->getTableName('sinch_products_mapping');
        $bundleMappingTable = $this->getTableName('sinch_bundle_mapping');
        $bundleItemsMappingTable = $this->getTableName('sinch_bundle_items_mapping');
        $bundleItemsProductMappingTable = $this->getTableName('sinch_bundle_items_product_mapping');

        // Start by clearing lists which don't feature in the file
        // This should also clear the related options, selections, and the values from the mapping table itself
        $this->startTimingStep('Delete removed requisition lists');
        $this->getConnection()->query(
            "DELETE FROM {$requisitionTable}
                WHERE requisition_id IN (
                    SELECT rim.magento_requisition_id
                    FROM {$this->requisitionListMappingTable} rim
                    LEFT JOIN {$this->requisitionListTable} ri
                        ON rim.sinch_requisition_id = ri.sinch_requisition_id
                    WHERE ri.sinch_requisition_id IS NULL
                )"
        );
        $this->endTimingStep();

        // Now items no longer part of the requisition lists
        $this->startTimingStep('Delete removed requisition items');
        $this->getConnection()->query(
            "DELETE FROM {$requisitionItemTable}
                WHERE requisition_item_id IN (
                    SELECT ripm.magento_item_id
                    FROM {$this->requisitionProductMappingTable} ripm
                    LEFT JOIN {$this->requisitionProductTable} rip
                        ON ripm.sinch_rq_product_id = rip.sinch_rq_product_id
                    WHERE rip.sinch_rq_product_id IS NULL
                )"
        );
        $this->endTimingStep();


        // The same but for bundles
        $this->startTimingStep('Delete removed requisition bundles');
        $this->getConnection()->query(
            "DELETE FROM {$requisitionItemTable}
                WHERE requisition_item_id IN (
                    SELECT ribm.magento_item_id
                    FROM {$this->requisitionBundleMappingTable} ribm
                    LEFT JOIN {$this->requisitionBundleTable} rib
                        ON ribm.sinch_rq_bundle_id = rib.sinch_rq_bundle_id
                    WHERE rib.sinch_rq_bundle_id IS NULL
                )"
        );
        $this->endTimingStep();

        // And for bundle selections
        // TODO: I think this one is unnecessary, verify
//        $this->startTimingStep('Delete removed requisition bundle selections');
//        $this->getConnection()->query(
//            "DELETE rio FROM {$requisitionItemOptionTable} rio
//                 INNER JOIN {$requisitionItemTable} ri ON rio.requisition_item_id = ri.requisition_item_id
//                 INNER JOIN {$this->requisitionBundleMappingTable} rbm ON ri.magento_item_id = rbm.magento_item_id
//                 INNER JOIN {$bundleItemsMappingTable} bim ON ri. = bim.sinch_id
//                 LEFT JOIN {$this->requisitionBundleItemsTable} rbi
//                     ON rbi.sinch_rq_bundle_id = rbm.sinch_rq_bundle_id
//                     AND rbi.sinch_bundle_item_id = bim.sinch_id
//                 WHERE rbi.sinch_bundle_item_id IS NULL"
//        );
//        $this->endTimingStep();

        // Create missing lists
        $this->startTimingStep('Create missing requisition lists');
        $missingListIds = $this->getConnection()->fetchCol(
            "SELECT ri.sinch_requisition_id
                    FROM {$this->requisitionListTable} ri
                    LEFT JOIN {$this->requisitionListMappingTable} rim
                        ON ri.sinch_requisition_id = rim.sinch_requisition_id
                    WHERE rim.sinch_requisition_id IS NULL"
        );

        foreach ($missingListIds as $missingListId) {
            try {
                $this->getConnection()->query(
                    "INSERT INTO {$requisitionTable} (title, description) (
                        SELECT name, description
                        FROM {$this->requisitionListTable}
                        WHERE sinch_requisition_id = :reqId
                    )",
                    [':reqId' => $missingListId]
                );

                $this->getConnection()->query(
                    "INSERT INTO {$this->requisitionListMappingTable} (sinch_requisition_id, magento_requisition_id) VALUES (:reqId, LAST_INSERT_ID())",
                    [":reqId" => $missingListId]
                );
            } catch (\Exception $e) {
                $this->logger->warning("Caught exception attempting to create Sinch requisition {$missingListId}: " . $e->getMessage());
                continue;
            }
        }
        $this->endTimingStep();

        // Create missing products
        $this->startTimingStep('Create missing requisition products');
        $missingProductIds = $this->getConnection()->fetchCol(
            "SELECT rip.sinch_rq_product_id
                FROM {$this->requisitionProductTable} rip
                LEFT JOIN {$this->requisitionProductMappingTable} ripm
                    ON rip.sinch_rq_product_id = ripm.sinch_rq_product_id
                WHERE ripm.sinch_rq_product_id IS NULL"
        );

        foreach ($missingProductIds as $missingProductId) {
            try {
                $this->getConnection()->query(
                    "INSERT INTO {$requisitionItemTable} (requisition_id, product_id, store_id, added_at, qty) (
                        SELECT rip.sinch_requisition_id,
                               spm.entity_id,
                               0,
                               NOW(),
                               rip.quantity
                        FROM {$this->requisitionProductTable} rip
                        INNER JOIN {$productMappingTable} spm
                            ON rip.sinch_product_id = spm.sinch_product_id
                        WHERE sinch_rq_product_id = :reqProdId
                    )",
                    [':reqProdId' => $missingProductId]
                );

                $this->getConnection()->query(
                    "INSERT INTO {$this->requisitionProductMappingTable} (sinch_rq_product_id, magento_item_id) VALUES (:reqProdId, LAST_INSERT_ID())",
                    [":reqProdId" => $missingProductId]
                );
            } catch (\Exception $e) {
                $this->logger->warning("Caught exception attempting to create Sinch requisition product {$missingProductId}: " . $e->getMessage());
                continue;
            }
        }
        $this->endTimingStep();

        // Create missing bundles
        $this->startTimingStep('Create missing requisition bundles');
        $missingBundleIds = $this->getConnection()->fetchCol(
            "SELECT rib.sinch_rq_bundle_id
                FROM {$this->requisitionBundleTable} rib
                LEFT JOIN {$this->requisitionBundleMappingTable} ribm
                    ON rib.sinch_rq_bundle_id = ribm.sinch_rq_bundle_id
                WHERE ribm.sinch_rq_bundle_id IS NULL"
        );

        foreach ($missingBundleIds as $missingBundleId) {
            try {
                $this->getConnection()->query(
                    "INSERT INTO {$requisitionItemTable} (requisition_id, product_id, store_id, added_at, qty) (
                        SELECT rib.sinch_requisition_id,
                               bm.magento_id,
                               0,
                               NOW(),
                               rib.quantity
                        FROM {$this->requisitionBundleTable} rib
                        INNER JOIN {$bundleMappingTable} bm
                            ON rib.sinch_bundle_id = bm.sinch_id
                        WHERE sinch_rq_bundle_id = :reqBundleId
                    )",
                    [':reqBundleId' => $missingBundleId]
                );

                $this->getConnection()->query(
                    "INSERT INTO {$this->requisitionBundleMappingTable} (sinch_rq_bundle_id, magento_item_id) VALUES (:reqBundleId, LAST_INSERT_ID())",
                    [":reqBundleId" => $missingBundleId]
                );
            } catch (\Exception $e) {
                $this->logger->warning("Caught exception attempting to create Sinch requisition bundle {$missingBundleId}: " . $e->getMessage());
                continue;
            }
        }
        $this->endTimingStep();

        // Create missing bundle selections
        $this->startTimingStep('Create/update requisition bundle selections');
        // info_buyRequest, bundle_identity, bundle_option_ids, bundle_selection_ids
        $itemsToPopulate = $this->getConnection()->fetchPairs(
            "SELECT ribm.magento_item_id, bm.magento_id FROM {$this->requisitionBundleMappingTable} ribm
                INNER JOIN {$bundleMappingTable} bm
                   ON ribm.sinch_rq_bundle_id = bm.sinch_id");

        foreach ($itemsToPopulate as $itemId => $bundleId) {
            try {
                $bundle = $this->productRepo->getById($bundleId);
                if ($bundle->getTypeId() !== 'bundle') {
                    $this->logger->warning("Product {$bundleId} is unexpectedly not a bundle");
                    continue;
                }

                // Check for the matching records we want
                $buyReq = $this->getNamedRequisitionOptionId($itemId, 'info_buyRequest');
                $bundleIdent = $this->getNamedRequisitionOptionId($itemId, 'bundle_identity');
                $bundleOptIds = $this->getNamedRequisitionOptionId($itemId, 'bundle_option_ids');
                $bundleSelectionIds = $this->getNamedRequisitionOptionId($itemId, 'bundle_selection_ids');

                if (empty($bundleIdent)) {
                    // Create bundle ident
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value) VALUES (:itemId, :bundleId, 'bundle_identity', :bundleId)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId]
                    );
                } else {
                    // Update bundle ident
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :bundleId WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":bundleId" => $bundleId, ":itemId" => $itemId, ":optionId" => $bundleIdent]
                    );
                }

                // Establish the options and selections
                $sinchSelections = $this->getConnection()->fetchAll(
                    "SELECT bim.magento_option, bipm.magento_selection FROM {$this->requisitionBundleItemsTable} rbi
                        INNER JOIN {$bundleItemsMappingTable} bim
                            ON rbi.sinch_bundle_item_id = bim.sinch_id
                        INNER JOIN {$bundleItemsProductMappingTable} bipm
                            ON rbi.sinch_bundle_item_id = bipm.sinch_item_id
                            AND rbi.sinch_product_id = bipm.sinch_product_id
                        INNER JOIN {$this->requisitionBundleTable} rb
                            ON rbi.sinch_rq_bundle_id = rb.sinch_rq_bundle_id
                        INNER JOIN {$bundleMappingTable} bm
                            ON rb.sinch_bundle_id = bm.sinch_id
                        WHERE bm.magento_id = :bundleId",
                    [":bundleId" => $bundleId]
                );
                $options = [];
                $selections = [];
                foreach ($sinchSelections as $selection) {
                    $options[] = $selection['magento_option'];
                    $selections[] = $selection['magento_selection'];
                }

                // TODO: Is this logic correct?
                if (empty($bundleOptIds)) {
                    // Create bundle option ids
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value) VALUES (:itemId, :bundleId, 'bundle_option_ids', :options)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId, ':options' => '[' . implode(',', $options) . ']']
                    );
                } else {
                    // Update bundle option ids
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :options WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":itemId" => $itemId, ":optionId" => $bundleOptIds, ":options" => '[' . implode(',', $options) . ']']
                    );
                }


                if (empty($bundleSelectionIds)) {
                    // Create bundle selection ids
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value) VALUES (:itemId, :bundleId, 'bundle_selection_ids', :options)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId, ':options' => '[' . implode(',', $selections) . ']']
                    );
                } else {
                    // Update bundle selection ids
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :options WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":itemId" => $itemId, ":optionId" => $bundleSelectionIds, ":options" => '[' . implode(',', $selections) . ']']
                    );
                }

                // BuyReq - probably the most complex and weird
                $buyReqObj = [];
                $buyReqObj['product'] = $bundleId;
                $buyReqObj['requisition_id'] = $this->getConnection()->fetchOne(
                    "SELECT requisition_id FROM {$requisitionItemTable} WHERE requisition_item_id = :reqItemId",
                    [":reqItemId" => $itemId]
                );
                $buyReqObj['serialized_data'] = [
                    'product' => $bundleId,
                    'bundle_option' => []
                ];
                foreach ($sinchSelections as $selection) {
                    $buyReqObj['serialized_data']['bundle_option'][$selection['magento_option']] = $selection['magento_selection'];
                }

                if (empty($buyReq)) {
                    // Create buy request
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value) VALUES (:itemId, :bundleId, 'info_buyRequest', :buyReq)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId, ':buyReq' => $this->jsonSerializer->serialize($buyReqObj)]
                    );
                } else {
                    // Update buy request
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :buyReq WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":itemId" => $itemId, ":optionId" => $buyReq, ":buyReq" => $this->jsonSerializer->serialize($buyReqObj)]
                    );
                }

            } catch (NoSuchEntityException) {
                $this->logger->error("Failed to find bundle with ID: {$bundleId}");
                continue;
            }
        }
        $this->endTimingStep();
    }

    /**
     * @inheritDoc
     */
    public function getRequiredFiles(): array
    {
        return [
            Download::FILE_REQUISITION_LIST,
            Download::FILE_REQUISITION_LIST_PRODUCTS,
            Download::FILE_REQUISITION_LIST_BUNDLES,
            Download::FILE_REQUISITION_LIST_BUNDLE_ITEMS_PRODUCTS
        ];
    }

    private function createTablesIfRequired(): void
    {
        $requisitionListTable = $this->getTableName('requisition');
        $requisitionItemTable = $this->getTableName('requisition_item');
        $requisitionOptionTable = $this->getTableName('requisition_item_option');
        $bundleTable = $this->getTableName('sinch_bundles');
        $bundleItemsTable = $this->getTableName('sinch_bundle_items');
        $bundleItemsProductTable = $this->getTableName('sinch_bundle_items_products');

        // ID|Name|Description
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionListTable} (
                sinch_requisition_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                name varchar(255) NOT NULL,
                description mediumtext
            )"
        );

        // ID|CoreListID|ProductID|Quantity|DaysInRequisition
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionProductTable} (
                sinch_rq_product_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                sinch_requisition_id int(11) UNSIGNED NOT NULL,
                sinch_product_id int(11) NOT NULL,
                quantity decimal(12,4) NOT NULL,
                days_in_requisition int(11),
                FOREIGN KEY (sinch_requisition_id) REFERENCES {$this->requisitionListTable} (sinch_requisition_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        // ID|CoreListID|BundleID|Quantity|DaysInRequisition
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionBundleTable} (
                sinch_rq_bundle_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                sinch_requisition_id int(11) UNSIGNED NOT NULL,
                sinch_bundle_id int(11) UNSIGNED NOT NULL,
                quantity decimal(12,4) NOT NULL,
                days_in_requisition int(11),
                FOREIGN KEY (sinch_requisition_id) REFERENCES {$this->requisitionListTable} (sinch_requisition_id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (sinch_bundle_id) REFERENCES {$bundleTable} (sinch_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        // CoreListBundleID|BundleItemID|ProductID
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionBundleItemsTable} (
                sinch_rq_bundle_id int(11) UNSIGNED NOT NULL,
                sinch_bundle_item_id int(11) UNSIGNED NOT NULL,
                sinch_product_id int(11) NOT NULL,
                PRIMARY KEY (sinch_rq_bundle_id, sinch_bundle_item_id),
                FOREIGN KEY (sinch_rq_bundle_id) REFERENCES {$this->requisitionBundleTable} (sinch_rq_bundle_id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (sinch_bundle_item_id) REFERENCES {$bundleItemsTable} (sinch_item_id) ON UPDATE CASCADE ON DELETE CASCADE,
                FOREIGN KEY (sinch_bundle_item_id, sinch_product_id) REFERENCES {$bundleItemsProductTable} (sinch_item_id, sinch_product_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        // Mapping tables
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionListMappingTable} (
                sinch_requisition_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                magento_requisition_id int(11) UNSIGNED NOT NULL UNIQUE,
                FOREIGN KEY (magento_requisition_id) REFERENCES {$requisitionListTable} (requisition_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionProductMappingTable} (
                sinch_rq_product_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                magento_item_id int(11) UNSIGNED NOT NULL UNIQUE,
                FOREIGN KEY (magento_item_id) REFERENCES {$requisitionItemTable} (requisition_item_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionBundleMappingTable} (
                sinch_rq_bundle_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                magento_item_id int(11) UNSIGNED NOT NULL UNIQUE,
                FOREIGN KEY (magento_item_id) REFERENCES {$requisitionItemTable} (requisition_item_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionBundleItemsMappingTable} (
                sinch_rq_bundle_id int(11) UNSIGNED NOT NULL,
                sinch_bundle_item_id int(11) UNSIGNED NOT NULL,
                magento_option_id int(11) UNSIGNED NOT NULL,
                PRIMARY KEY (sinch_rq_bundle_id, sinch_bundle_item_id),
                FOREIGN KEY (magento_option_id) REFERENCES {$requisitionOptionTable} (option_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );
    }

    private function getNamedRequisitionOptionId(int $requisitionItemId, string $optionName): ?int
    {
        $requisitionItemOptionTable = $this->getTableName('requisition_item_option');
        return $this->getConnection()->fetchOne(
            "SELECT option_id
                FROM {$requisitionItemOptionTable}
                WHERE requisition_item_id = :itemId
                    AND code = :optionName",
            [":itemId" => $requisitionItemId, ":optionName" => $optionName]
        );
    }
}