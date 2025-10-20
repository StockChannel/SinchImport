<?php

namespace SITC\Sinchimport\Observer\PreImport;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SITC\Sinchimport\Logger\Logger;

/**
 * Backup the bundle products prior to full import (in REWRITE mode) so we can restore them post import (handles sinchimport_import_start_full)
 */
class BackupBundles implements ObserverInterface
{

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private Logger $logger,
    ) {
        $this->logger = $logger->withName("BackupBundles");
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        // Check product mode
        $productMode = $observer->getEvent()->getData('productMode');
        if ($productMode !== 'REWRITE') {
            // Don't need to do anything if its not in REWRITE mode
            return;
        }

        $this->logger->info("Starting backup of bundle products");
        $bundleSelectionTable = $this->resourceConnection->getTableName('catalog_product_bundle_selection');
        $catalogProductEntity = $this->resourceConnection->getTableName('catalog_product_entity');
        $backupTable = $this->resourceConnection->getTableName('sinchimport_bundle_product_backup');
        $this->resourceConnection->getConnection()->query(
            "REPLACE INTO {$backupTable} (
                selection_id,
                option_id,
                parent_product_id,
                product_sku,
                position,
                is_default,
                selection_price_type,
                selection_price_value,
                selection_qty,
                selection_can_change_qty
            )
            SELECT
                cpbs.selection_id,
                cpbs.option_id,
                cpbs.parent_product_id,
                cpe.sku,
                cpbs.position,
                cpbs.is_default,
                cpbs.selection_price_type,
                cpbs.selection_price_value,
                cpbs.selection_qty,
                cpbs.selection_can_change_qty
            FROM {$bundleSelectionTable} cpbs
            INNER JOIN {$catalogProductEntity} cpe
                ON cpbs.product_id = cpe.entity_id"
        );

        $backedUpRows = $this->resourceConnection->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM {$backupTable}"
        );
        $this->logger->info("Backup of bundle products complete, {$backedUpRows} rows are backed up");
    }
}