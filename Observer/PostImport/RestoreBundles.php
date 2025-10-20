<?php

namespace SITC\Sinchimport\Observer\PostImport;

use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SITC\Sinchimport\Logger\Logger;

/**
 * Restore the backed up bundle products at the end of the import (handles sinchimport_post_import)
 */
class RestoreBundles implements ObserverInterface
{
    private string $bundleSelectionTable;
    private string $catalogProductEntity;
    private string $backupTable;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private Logger $logger,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
        $this->logger = $logger->withName("RestoreBundles");
        $this->bundleSelectionTable = $this->resourceConnection->getTableName('catalog_product_bundle_selection');
        $this->catalogProductEntity = $this->resourceConnection->getTableName('catalog_product_entity');
        $this->backupTable = $this->resourceConnection->getTableName('sinchimport_bundle_product_backup');
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $importType = $observer->getEvent()->getData('import_type');
        if ($importType !== 'FULL') {
            // Don't need to do anything if this isn't a full import
            return;
        }
        $productMode = $this->scopeConfig->getValue('sinchimport/sinch_ftp/replace_product');
        if ($productMode !== 'REWRITE') {
            // Don't need to do anything if product mode is not REWRITE
            return;
        }
        $this->logger->info("Starting restore of bundle products");
        // We'll load all rows into memory and process them one by one, deleting rows from the backup table as we successfully restore it into the bundle selections

        // We'll manually select all columns (except the original primary key), just to make sure we know what columns we're getting and to make it easier to reference in the rest of this observer
        // We'll also do the inverse of the backup, remapping SKU back to the product ID in this select to reduce the amount of extra work we need to do later on
        $conn = $this->resourceConnection->getConnection();
        $backedUpRows = $conn->fetchAll(
            "SELECT
                bpb.id,
                bpb.option_id,
                bpb.parent_product_id,
                cpe.entity_id as product_id,
                bpb.position,
                bpb.is_default,
                bpb.selection_price_type,
                bpb.selection_price_value,
                bpb.selection_qty,
                bpb.selection_can_change_qty
            FROM {$this->backupTable} bpb
            INNER JOIN {$this->catalogProductEntity} cpe
                ON bpb.product_sku = cpe.sku"
        );
        $numRows = count($backedUpRows);
        $this->logger->info("Retrieved {$numRows} backed up rows from our backup table");
        foreach ($backedUpRows as $row) {
            try {
                // Check to see if the bundle selection table already contains a row we believe to be the same as the one we want to restore
                $matchingRows = $conn->fetchOne(
                    "SELECT COUNT(*)
                    FROM {$this->bundleSelectionTable}
                    WHERE option_id = :option_id
                    AND parent_product_id = :parent_product
                    AND position = :position
                    AND product_id = :product_id",
                    [
                        ":option_id" => $row["option_id"],
                        ":parent_product" => $row["parent_product_id"],
                        ":position" => $row["position"],
                        ":product_id" => $row["product_id"]
                    ]
                );
                if ($matchingRows != 0) {
                    $this->logger->warning("Product {$row["product_id"]} for bundle {$row["parent_product_id"]} in option {$row["option_id"]} seems to already exist, skipping restore (but deleting backup row)");
                    // Delete the backed up row as its likely its either not a sinch product (and therefore the row never dropped), or has been manually fixed during the running import
                    $this->deleteBackupRow($conn, $row);
                    continue;
                }
                //Unset the id column so it doesn't crash on insert
                unset($row['id']);
                $inserted = $conn->insert($this->bundleSelectionTable, $row);
                if ($inserted > 0) {
                    $this->logger->info("Product {$row["product_id"]} for bundle {$row["parent_product_id"]} in option {$row["option_id"]} was restored successfully, deleting backup row");
                    $this->deleteBackupRow($conn, $row);
                }
            } catch (Exception $e) {
                $this->logger->error("Caught exception during backup row processing: {$e->getMessage()}");
            }
        }
        $remainingRows = $conn->fetchOne("SELECT COUNT(*) FROM {$this->backupTable}");
        $this->logger->info("Restore of bundle products complete, $remainingRows rows remain in the backup table");
    }

    /**
     *
     * @param AdapterInterface $conn
     * @param array $row
     * @return void
     */
    private function deleteBackupRow(AdapterInterface $conn, array $row): void
    {

        $conn->query(
            "DELETE FROM {$this->backupTable}
                    WHERE parent_product_id = :parent_product
                    AND option_id = :option_id
                    AND position = :position
                    AND product_sku = (
                        SELECT sku
                        FROM {$this->catalogProductEntity}
                        WHERE entity_id = :product_id
                    )",
            [
                ":option_id" => $row["option_id"],
                ":parent_product" => $row["parent_product_id"],
                ":position" => $row["position"],
                ":product_id" => $row["product_id"]
            ]
        );
    }
}