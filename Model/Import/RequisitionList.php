<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
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

    public function __construct(
        ResourceConnection    $resourceConn,
        ConsoleOutput         $output,
        Download              $downloadHelper,
        private readonly ProductRepositoryInterface $productRepo,
        private readonly Json $jsonSerializer,
        private readonly Data $helper,
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
        // ID|Name|Description|AccountGroupID
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$requisitionFile}'
                INTO TABLE {$this->requisitionListTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_requisition_id, name, description, sinch_account_group)"
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

        // Resolve the EAV attribute id for the customer 'account_id' attribute (Tigren_CompanyAccount)
        $accountAttrId = $this->accountIdAttributeId();
        if ($accountAttrId === null) {
            $this->logger->error("Could not resolve customer 'account_id' attribute, aborting requisition import");
            return;
        }

        // One list per (sinch_requisition_id, account_id), owned by the lowest customer in the account
        $desiredRows = $this->desiredAssignments($accountAttrId);
        $desiredLists = [];
        foreach ($desiredRows as $row) {
            $desiredLists[$row['sinch_requisition_id'] . ':' . $row['account_id']] = (int)$row['first_customer_id'];
        }

        $connection = $this->getConnection();

        try {
            $connection->beginTransaction();

            // Delete per-account item copies that are no longer part of a desired (list, account).
            //  Items removed from a list are dropped here; empty lists are handled by the requisition deletion below.
            $this->startTimingStep('Delete removed requisition items');
            $this->deleteRemovedItems($this->desiredProductKeys($accountAttrId), $this->requisitionProductMappingTable, 'sinch_rq_product_id');
            $this->deleteRemovedItems($this->desiredBundleKeys($accountAttrId), $this->requisitionBundleMappingTable, 'sinch_rq_bundle_id');
            $this->endTimingStep();

            // Delete lists whose (list, account) combination is no longer desired
            $this->startTimingStep('Delete removed requisition lists');
            $this->deleteRemovedLists($desiredLists);
            $this->endTimingStep();

            // Create missing lists
            $this->startTimingStep('Create missing requisition lists');
            $this->createMissingLists($desiredLists);
            $this->endTimingStep();

            // Reassign owner customer if the lowest customer on the account has changed
            $this->startTimingStep('Reassign requisition list owners');
            $this->reassignOwners($desiredLists);
            $this->endTimingStep();

            // Create missing per-account product items
            $this->startTimingStep('Create missing requisition products');
            $this->createMissingProductItems($desiredLists);
            // Ensure an info_buyRequest option row for each simple product item copy (needed for frontend visibility)
            $this->ensureProductBuyRequest();
            $this->endTimingStep();

            // F) Create missing per-account bundle items
            $this->startTimingStep('Create missing requisition bundles');
            $this->createMissingBundleItems($desiredLists);
            $this->endTimingStep();

            $connection->commit();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $connection->rollBack();
            throw $e;
        }

        // G) Bundle selections/options/buyRequest - content is per bundle, duplicated across per-account item copies
        $this->startTimingStep('Create/update requisition bundle selections');
        $this->populateBundleSelections(
            $this->getTableName('requisition_item'),
            $this->getTableName('requisition_item_option'),
            $this->getTableName('sinch_bundle_mapping'),
            $this->getTableName('sinch_bundle_items_mapping'),
            $this->getTableName('sinch_bundle_items_product_mapping')
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    /**
     * Resolve the EAV attribute id for the customer 'account_id' attribute (Tigren_CompanyAccount),
     * or null if it does not exist
     *
     * @return int|null
     */
    private function accountIdAttributeId(): ?int
    {
        $id = $this->getConnection()->fetchOne(
            "SELECT eav.attribute_id
                FROM {$this->getTableName('eav_attribute')} eav
                INNER JOIN {$this->getTableName('eav_entity_type')} et
                    ON et.entity_type_id = eav.entity_type_id
                WHERE et.entity_type_code = 'customer'
                AND eav.attribute_code = 'account_id'"
        );
        return is_numeric($id) ? (int)$id : null;
    }

    /**
     * The desired assignment set: for each account belonging to an account group that has requisition
     * list(s) in the import, the owner customer id (lowest customer on the account).
     * One list is created per (sinch_requisition_id, account_id).
     *
     * @param int $accountAttrId EAV attribute id for the customer 'account_id' attribute
     * @return array rows of sinch_requisition_id, account_id, first_customer_id
     */
    private function desiredAssignments(int $accountAttrId): array
    {
        $accountTable = $this->getTableName('tigren_comaccount_account');
        $customerEntityTable = $this->getTableName('customer_entity');
        $customerEntityIntTable = $this->getTableName('customer_entity_int');

        return $this->getConnection()->fetchAll(
            "SELECT rl.sinch_requisition_id,
                    acc.account_id,
                    MIN(ce.entity_id) AS first_customer_id
                FROM {$this->requisitionListTable} rl
                INNER JOIN {$accountTable} acc
                    ON acc.account_group_id = rl.sinch_account_group
                INNER JOIN {$customerEntityIntTable} cei
                    ON cei.attribute_id = :attrId
                    AND cei.value = acc.account_id
                    AND cei.value > 0
                INNER JOIN {$customerEntityTable} ce
                    ON ce.entity_id = cei.entity_id
                GROUP BY rl.sinch_requisition_id, acc.account_id",
            [":attrId" => $accountAttrId]
        );
    }

    /**
     * Desired per-account item keys for the given import item table (products or bundles).
     * A key is "sinch_rq_*_id:account_id" and is desired whenever the item's list maps
     * to a real account with customers.
     *
     * @param int $accountAttrId
     * @param string $itemTable import item table name
     * @param string $sinchIdColumn the item id column on that table
     * @return array key => true
     */
    private function buildDesiredItemKeys(int $accountAttrId, string $itemTable, string $sinchIdColumn): array
    {
        $accountTable = $this->getTableName('tigren_comaccount_account');
        $customerEntityIntTable = $this->getTableName('customer_entity_int');

        $rows = $this->getConnection()->fetchAll(
            "SELECT {$itemTable}.{$sinchIdColumn},
                    acc.account_id
                FROM {$itemTable}
                INNER JOIN {$this->requisitionListTable} rl
                    ON {$itemTable}.sinch_requisition_id = rl.sinch_requisition_id
                INNER JOIN {$accountTable} acc
                    ON acc.account_group_id = rl.sinch_account_group
                INNER JOIN {$customerEntityIntTable} cei
                    ON cei.attribute_id = :attrId
                    AND cei.value = acc.account_id
                    AND cei.value > 0
                GROUP BY {$itemTable}.{$sinchIdColumn}, acc.account_id",
            [":attrId" => $accountAttrId]
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row[$sinchIdColumn] . ':' . $row['account_id']] = true;
        }
        return $keys;
    }

    /**
     * Delete per-account requisition item copies whose (item, account) key is no longer desired.
     * Deleting the requisition_item rows cascades the mapping rows (FK with ON DELETE CASCADE).
     *
     * @param array $desiredKeys key => true
     * @param string $mappingTable
     * @param string $sinchIdColumn the sinch id column on the mapping table
     * @return void
     */
    private function deleteRemovedItems(array $desiredKeys, string $mappingTable, string $sinchIdColumn): void
    {
        $existing = $this->getConnection()->fetchAll(
            "SELECT {$sinchIdColumn}, account_id, magento_item_id FROM {$mappingTable}"
        );

        $idsToDelete = [];
        foreach ($existing as $row) {
            $key = $row[$sinchIdColumn] . ':' . $row['account_id'];
            if (!isset($desiredKeys[$key])) {
                $idsToDelete[] = (int)$row['magento_item_id'];
            }
        }

        if (empty($idsToDelete)) {
            return;
        }

        $this->getConnection()->query(
            "DELETE FROM {$this->getTableName('requisition_item')}
                WHERE requisition_item_id IN (" . implode(',', $idsToDelete) . ")"
        );
    }

    /**
     * Desired per-account product item keys
     *
     * @param int $accountAttrId
     * @return array key => true
     */
    private function desiredProductKeys(int $accountAttrId): array
    {
        return $this->buildDesiredItemKeys($accountAttrId, $this->requisitionProductTable, 'sinch_rq_product_id');
    }

    /**
     * Desired per-account bundle item keys
     *
     * @param int $accountAttrId
     * @return array key => true
     */
    private function desiredBundleKeys(int $accountAttrId): array
    {
        return $this->buildDesiredItemKeys($accountAttrId, $this->requisitionBundleTable, 'sinch_rq_bundle_id');
    }

    /**
     * Delete requisition lists (and they cascade their items/mappings) whose
     * (sinch_requisition_id, account_id) combination is no longer desired
     *
     * @param array $desiredLists "list:account" => first_customer_id
     * @return void
     */
    private function deleteRemovedLists(array $desiredLists): void
    {
        $existing = $this->getConnection()->fetchAll(
            "SELECT sinch_requisition_id, account_id, magento_requisition_id FROM {$this->requisitionListMappingTable}"
        );

        $idsToDelete = [];
        foreach ($existing as $row) {
            $key = $row['sinch_requisition_id'] . ':' . $row['account_id'];
            if (!isset($desiredLists[$key])) {
                $idsToDelete[] = (int)$row['magento_requisition_id'];
            }
        }

        if (empty($idsToDelete)) {
            return;
        }

        $this->getConnection()->query(
            "DELETE FROM {$this->getTableName('requisition')}
                WHERE requisition_id IN (" . implode(',', $idsToDelete) . ")"
        );
    }

    /**
     * Create the missing requisition lists (one per desired (list, account)), each owned by the
     * account's owner customer, and record them in the mapping table
     *
     * @param array $desiredLists "list:account" => first_customer_id
     * @return void
     */
    private function createMissingLists(array $desiredLists): void
    {
        $existing = $this->getConnection()->fetchAll(
            "SELECT sinch_requisition_id, account_id FROM {$this->requisitionListMappingTable}"
        );
        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$row['sinch_requisition_id'] . ':' . $row['account_id']] = true;
        }

        $requisitionTable = $this->getTableName('requisition');

        foreach ($desiredLists as $key => $firstCustomerId) {
            if (isset($existingKeys[$key])) {
                continue;
            }
            [$reqId, $accountId] = explode(':', $key);

            $list = $this->getConnection()->fetchRow(
                "SELECT name, description FROM {$this->requisitionListTable}
                    WHERE sinch_requisition_id = :reqId",
                [":reqId" => $reqId]
            );
            if ($list === false || $list['name'] === null) {
                $this->logger->warning("Skipping requisition {$reqId} for account {$accountId}: no list data");
                continue;
            }

            $this->getConnection()->insert(
                $requisitionTable,
                [
                    'title' => $list['name'],
                    'description' => $list['description'],
                    'customer_id' => $firstCustomerId,
                ]
            );
            $requisitionId = $this->getConnection()->lastInsertId($requisitionTable);

            $this->getConnection()->insert(
                $this->requisitionListMappingTable,
                [
                    'sinch_requisition_id' => $reqId,
                    'account_id' => $accountId,
                    'customer_id' => $firstCustomerId,
                    'magento_requisition_id' => $requisitionId,
                ]
            );
        }
    }

    /**
     * Reassign the owner customer of existing lists when the lowest customer on the account has changed
     *
     * @param array $desiredLists "list:account" => first_customer_id
     * @return void
     */
    private function reassignOwners(array $desiredLists): void
    {
        $existing = $this->getConnection()->fetchAll(
            "SELECT sinch_requisition_id, account_id, customer_id, magento_requisition_id FROM {$this->requisitionListMappingTable}"
        );

        $requisitionTable = $this->getTableName('requisition');

        foreach ($existing as $row) {
            $key = $row['sinch_requisition_id'] . ':' . $row['account_id'];
            if (!isset($desiredLists[$key])) {
                continue; // removed by deleteRemovedLists already
            }
            $newCustomer = $desiredLists[$key];
            if ((int)$row['customer_id'] === $newCustomer) {
                continue;
            }

            $this->getConnection()->query(
                "UPDATE {$requisitionTable} SET customer_id = :cust WHERE requisition_id = :reqId",
                [":cust" => $newCustomer, ":reqId" => (int)$row['magento_requisition_id']]
            );
            $this->getConnection()->query(
                "UPDATE {$this->requisitionListMappingTable} SET customer_id = :cust
                    WHERE sinch_requisition_id = :sinch AND account_id = :account",
                [
                    ":cust" => $newCustomer,
                    ":sinch" => (int)$row['sinch_requisition_id'],
                    ":account" => (int)$row['account_id'],
                ]
            );
        }
    }

    /**
     * Create missing per-account product items for every desired (list, account), attached to the
     * mapped (account-specific) requisition row
     *
     * @param array $desiredLists "list:account" => first_customer_id
     * @return void
     */
    private function createMissingProductItems(array $desiredLists): void
    {
        $existing = $this->getConnection()->fetchAll(
            "SELECT sinch_rq_product_id, account_id FROM {$this->requisitionProductMappingTable}"
        );
        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$row['sinch_rq_product_id'] . ':' . $row['account_id']] = true;
        }

        $requisitionItemTable = $this->getTableName('requisition_item');
        $productMappingTable = $this->getTableName('sinch_products_mapping');

        foreach ($desiredLists as $key => $firstCustomerId) {
            [$reqId, $accountId] = explode(':', $key);

            $magentoReqId = $this->getConnection()->fetchOne(
                "SELECT magento_requisition_id FROM {$this->requisitionListMappingTable}
                    WHERE sinch_requisition_id = :reqId AND account_id = :accountId",
                [":reqId" => $reqId, ":accountId" => $accountId]
            );
            if (!$magentoReqId) {
                continue; // list not created (e.g. missing data); nothing to attach
            }

            $products = $this->getConnection()->fetchAll(
                "SELECT sinch_rq_product_id, quantity FROM {$this->requisitionProductTable}
                    WHERE sinch_requisition_id = :reqId",
                [":reqId" => $reqId]
            );

            foreach ($products as $product) {
                $pKey = $product['sinch_rq_product_id'] . ':' . $accountId;
                if (isset($existingKeys[$pKey])) {
                    continue;
                }

                $entityId = $this->getConnection()->fetchOne(
                    "SELECT spm.entity_id
                        FROM {$this->requisitionProductTable} rp
                        INNER JOIN {$productMappingTable} spm
                            ON rp.sinch_product_id = spm.sinch_product_id
                        WHERE rp.sinch_rq_product_id = :prodId",
                    [":prodId" => $product['sinch_rq_product_id']]
                );
                if (!$entityId) {
                    $this->logger->warning("Sinch product not mapped (sinch_rq_product_id {$product['sinch_rq_product_id']}), skipping");
                    continue;
                }

                $this->getConnection()->insert(
                    $requisitionItemTable,
                    [
                        'requisition_id' => (int)$magentoReqId,
                        'product_id' => (int)$entityId,
                        'store_id' => $this->helper->getDefaultStoreId(),
                        'added_at' => date('Y-m-d H:i:s'),
                        'qty' => $product['quantity'],
                    ]
                );
                $itemId = $this->getConnection()->lastInsertId($requisitionItemTable);

                $this->getConnection()->insert(
                    $this->requisitionProductMappingTable,
                    [
                        'sinch_rq_product_id' => $product['sinch_rq_product_id'],
                        'account_id' => $accountId,
                        'magento_item_id' => $itemId,
                    ]
                );
            }
        }
    }

    /**
     * Ensure an info_buyRequest option row exists for every per-account simple product item copy.
     * Simple products need this row for frontend visibility, so it is ensured for all product items
     * (not just newly created ones), inserting or updating the value each import.
     *
     * @return void
     */
    private function ensureProductBuyRequest(): void
    {
        $requisitionItemTable = $this->getTableName('requisition_item');
        $requisitionItemOptionTable = $this->getTableName('requisition_item_option');

        $items = $this->getConnection()->fetchAll(
            "SELECT rpm.magento_item_id, ri.product_id
                FROM {$this->requisitionProductMappingTable} rpm
                INNER JOIN {$requisitionItemTable} ri
                    ON ri.requisition_item_id = rpm.magento_item_id"
        );

        foreach ($items as $row) {
            $itemId = (int)$row['magento_item_id'];
            $productId = (int)$row['product_id'];

            $existing = $this->getNamedRequisitionOptionId($itemId, 'info_buyRequest');

            if ($existing === null) {
                $this->getConnection()->query(
                    "INSERT INTO {$requisitionItemOptionTable}
                        (requisition_item_id, product_id, code, value)
                        VALUES (:itemId, :productId, 'info_buyRequest', '[]')",
                    [":itemId" => $itemId, ":productId" => $productId]
                );
            } else {
                $this->getConnection()->query(
                    "UPDATE {$requisitionItemOptionTable} SET value = '[]'
                        WHERE requisition_item_id = :itemId AND option_id = :optionId",
                    [":itemId" => $itemId, ":optionId" => $existing]
                );
            }
        }
    }

    /**
     * Create missing per-account bundle items for every desired (list, account),
     * attached to the mapped (account-specific) requisition row
     *
     * @param array $desiredLists "list:account" => first_customer_id
     * @return void
     */
    private function createMissingBundleItems(array $desiredLists): void
    {
        $existing = $this->getConnection()->fetchAll(
            "SELECT sinch_rq_bundle_id, account_id FROM {$this->requisitionBundleMappingTable}"
        );
        $existingKeys = [];
        foreach ($existing as $row) {
            $existingKeys[$row['sinch_rq_bundle_id'] . ':' . $row['account_id']] = true;
        }

        $requisitionItemTable = $this->getTableName('requisition_item');
        $bundleMappingTable = $this->getTableName('sinch_bundle_mapping');

        foreach ($desiredLists as $key => $firstCustomerId) {
            [$reqId, $accountId] = explode(':', $key);

            $magentoReqId = $this->getConnection()->fetchOne(
                "SELECT magento_requisition_id FROM {$this->requisitionListMappingTable}
                    WHERE sinch_requisition_id = :reqId AND account_id = :accountId",
                [":reqId" => $reqId, ":accountId" => $accountId]
            );
            if (!$magentoReqId) {
                continue;
            }

            $bundles = $this->getConnection()->fetchAll(
                "SELECT sinch_rq_bundle_id, quantity FROM {$this->requisitionBundleTable}
                    WHERE sinch_requisition_id = :reqId",
                [":reqId" => $reqId]
            );

            foreach ($bundles as $bundle) {
                $bKey = $bundle['sinch_rq_bundle_id'] . ':' . $accountId;
                if (isset($existingKeys[$bKey])) {
                    continue;
                }

                $bundleMagentoId = $this->getConnection()->fetchOne(
                    "SELECT bm.magento_id
                        FROM {$this->requisitionBundleTable} rb
                        INNER JOIN {$bundleMappingTable} bm
                            ON rb.sinch_bundle_id = bm.sinch_id
                        WHERE rb.sinch_rq_bundle_id = :bundleId",
                    [":bundleId" => $bundle['sinch_rq_bundle_id']]
                );
                if (!$bundleMagentoId) {
                    $this->logger->warning("Sinch bundle not mapped (sinch_rq_bundle_id {$bundle['sinch_rq_bundle_id']}), skipping");
                    continue;
                }

                $this->getConnection()->insert(
                    $requisitionItemTable,
                    [
                        'requisition_id' => (int)$magentoReqId,
                        'product_id' => (int)$bundleMagentoId,
                        'store_id' => $this->helper->getDefaultStoreId(),
                        'added_at' => date('Y-m-d H:i:s'),
                        'qty' => $bundle['quantity'],
                    ]
                );
                $itemId = $this->getConnection()->lastInsertId($requisitionItemTable);

                $this->getConnection()->insert(
                    $this->requisitionBundleMappingTable,
                    [
                        'sinch_rq_bundle_id' => $bundle['sinch_rq_bundle_id'],
                        'account_id' => $accountId,
                        'magento_item_id' => $itemId,
                    ]
                );
            }
        }
    }

    /**
     * Populate the bundle option/selection/buyRequest data for every per-account item copy.
     * The bundle content itself is identical across accounts; the records are duplicated
     * under each account's requisition item.
     *
     * @param string $requisitionItemTable
     * @param string $requisitionItemOptionTable
     * @param string $bundleMappingTable
     * @param string $bundleItemsMappingTable
     * @param string $bundleItemsProductMappingTable
     * @return void
     */
    private function populateBundleSelections(
        string $requisitionItemTable,
        string $requisitionItemOptionTable,
        string $bundleMappingTable,
        string $bundleItemsMappingTable,
        string $bundleItemsProductMappingTable
    ): void {
        $itemsToPopulate = $this->getConnection()->fetchAll(
            "SELECT rbpm.magento_item_id, bm.magento_id AS bundle_id
                FROM {$this->requisitionBundleMappingTable} rbpm
                INNER JOIN {$this->requisitionBundleTable} rb
                    on rbpm.sinch_rq_bundle_id = rb.sinch_rq_bundle_id
                INNER JOIN {$bundleMappingTable} bm
                    ON rb.sinch_bundle_id = bm.sinch_id"
        );

        foreach ($itemsToPopulate as $row) {
            $itemId = (int)$row['magento_item_id'];
            $bundleId = (int)$row['bundle_id'];

            $this->log("Populating item {$itemId}, bundle {$bundleId}");

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

                if ($bundleIdent === null) {
                    // Create bundle ident
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value)
                            VALUES (:itemId, :bundleId, 'bundle_identity', :bundleId)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId]
                    );
                } else {
                    // Update bundle ident
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :bundleId
                            WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":bundleId" => $bundleId, ":itemId" => $itemId, ":optionId" => $bundleIdent]
                    );
                }

                // Establish the options and selections
                $sinchSelections = $this->getConnection()->fetchAll(
                    "SELECT bim.magento_option, bipm.magento_selection
                        FROM {$this->requisitionBundleItemsTable} rbi
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
                if ($bundleOptIds === null) {
                    // Create bundle option ids
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value)
                            VALUES (:itemId, :bundleId, 'bundle_option_ids', :options)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId, ':options' => '[' . implode(',', $options) . ']']
                    );
                } else {
                    // Update bundle option ids
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :options
                            WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":itemId" => $itemId, ":optionId" => $bundleOptIds, ":options" => '[' . implode(',', $options) . ']']
                    );
                }

                if ($bundleSelectionIds === null) {
                    // Create bundle selection ids
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value)
                            VALUES (:itemId, :bundleId, 'bundle_selection_ids', :options)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId, ':options' => '[' . implode(',', $selections) . ']']
                    );
                } else {
                    // Update bundle selection ids
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :options
                            WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":itemId" => $itemId, ":optionId" => $bundleSelectionIds, ":options" => '[' . implode(',', $selections) . ']']
                    );
                }

                // BuyReq - probably the most complex and weird
                $buyReqObj = [];
                $buyReqObj['product'] = $bundleId;
                $buyReqObj['requisition_id'] = $this->getConnection()->fetchOne(
                    "SELECT requisition_id FROM {$requisitionItemTable}
                        WHERE requisition_item_id = :reqItemId",
                    [":reqItemId" => $itemId]
                );
                $buyReqObj['serialized_data'] = [
                    'product' => $bundleId,
                    'bundle_option' => []
                ];
                foreach ($sinchSelections as $selection) {
                    $buyReqObj['serialized_data']['bundle_option'][$selection['magento_option']] = $selection['magento_selection'];
                }

                if ($buyReq === null) {
                    // Create buy request
                    $this->getConnection()->query(
                        "INSERT INTO {$requisitionItemOptionTable} (requisition_item_id, product_id, code, value)
                            VALUES (:itemId, :bundleId, 'info_buyRequest', :buyReq)",
                        [":itemId" => $itemId, ":bundleId" => $bundleId, ':buyReq' => $this->jsonSerializer->serialize($buyReqObj)]
                    );
                } else {
                    // Update buy request
                    $this->getConnection()->query(
                        "UPDATE {$requisitionItemOptionTable} SET value = :buyReq
                            WHERE requisition_item_id = :itemId AND option_id = :optionId",
                        [":itemId" => $itemId, ":optionId" => $buyReq, ":buyReq" => $this->jsonSerializer->serialize($buyReqObj)]
                    );
                }

            } catch (NoSuchEntityException) {
                $this->logger->error("Failed to find bundle with ID: {$bundleId}");
                continue;
            }
        }
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
        $bundleTable = $this->getTableName('sinch_bundles');
        $bundleItemsTable = $this->getTableName('sinch_bundle_items');
        $bundleItemsProductTable = $this->getTableName('sinch_bundle_items_products');

        // ID|Name|Description
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionListTable} (
                sinch_requisition_id int(11) UNSIGNED NOT NULL PRIMARY KEY,
                name varchar(255) NOT NULL,
                description mediumtext,
                sinch_account_group int(11) UNSIGNED NOT NULL
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
                sinch_requisition_id int(11) UNSIGNED NOT NULL,
                account_id int(11) UNSIGNED NOT NULL,
                customer_id int(11) UNSIGNED NOT NULL,
                magento_requisition_id int(11) UNSIGNED NOT NULL UNIQUE,
                PRIMARY KEY (sinch_requisition_id, account_id),
                FOREIGN KEY (magento_requisition_id) REFERENCES {$requisitionListTable} (requisition_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionProductMappingTable} (
                sinch_rq_product_id int(11) UNSIGNED NOT NULL,
                account_id int(11) UNSIGNED NOT NULL,
                magento_item_id int(11) UNSIGNED NOT NULL UNIQUE,
                PRIMARY KEY (sinch_rq_product_id, account_id),
                FOREIGN KEY (magento_item_id) REFERENCES {$requisitionItemTable} (requisition_item_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );

        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->requisitionBundleMappingTable} (
                sinch_rq_bundle_id int(11) UNSIGNED NOT NULL,
                account_id int(11) UNSIGNED NOT NULL,
                magento_item_id int(11) UNSIGNED NOT NULL UNIQUE,
                PRIMARY KEY (sinch_rq_bundle_id, account_id),
                FOREIGN KEY (magento_item_id) REFERENCES {$requisitionItemTable} (requisition_item_id) ON UPDATE CASCADE ON DELETE CASCADE
            )"
        );
    }

    private function getNamedRequisitionOptionId(int $requisitionItemId, string $optionName): ?int
    {
        $requisitionItemOptionTable = $this->getTableName('requisition_item_option');
        $id = $this->getConnection()->fetchOne(
            "SELECT option_id
                FROM {$requisitionItemOptionTable}
                WHERE requisition_item_id = :itemId
                    AND code = :optionName",
            [":itemId" => $requisitionItemId, ":optionName" => $optionName]
        );
        // fetchOne returns false when no row exists - normalize to null so callers can test === null
        return ($id == null || $id == false) ? null : (int)$id;
    }
}