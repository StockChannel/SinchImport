<?php

namespace SITC\Sinchimport\Cron;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Sinch;

/**
 * Pickup new import runs from the database (triggered by admin panel), and start them
 */
class PickupImport
{
    /** @var ResourceConnection $resourceConn */
    private $resourceConn;

    /** @var Sinch $sinch */
    private $sinch;

    /** @var Logger $logger */
    private $logger;

    //Table name
    private $importStatusTable;

    public function __construct(
        ResourceConnection $resourceConn,
        Sinch $sinch,
        Logger $logger,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager
    ) {
        $this->resourceConn = $resourceConn;
        $this->sinch = $sinch;
        $this->logger = $logger->withName("PickupImport");

        //Get the table names
        $this->importStatusTable = $this->resourceConn->getTableName('sinch_import_status_statistic');
    }

    /**
     * Cron job to fetch scheduled imports (from admin) and start them
     *
     * @return void
     */
    public function execute()
    {
        //Clear missed records
        $this->resourceConn->getConnection()->query(
            "DELETE FROM {$this->importStatusTable}
                WHERE import_run_type = 'MANUAL'
                AND global_status_import = 'Scheduled'
                AND start_import < NOW() - INTERVAL 5 MINUTE"
        );

        $importType = $this->resourceConn->getConnection()->fetchOne(
            "SELECT import_type FROM {$this->importStatusTable}
                WHERE import_run_type = 'MANUAL'
                AND global_status_import = 'Scheduled'
                AND start_import > NOW() - INTERVAL 5 MINUTE"
        );

        if(!empty($importType) && !$this->sinch->canImport()) {
            //Import scheduled, but one is already running
            $this->logger->info("An import of type '{$importType}' is scheduled, but an import is already running");
            return;
        }
        if(!empty($importType)) {
            try {
                $this->emulation->startEnvironmentEmulation($this->storeManager->getDefaultStoreView()->getId(), Area::AREA_ADMINHTML);

                switch (strtoupper($importType)) {
                    case 'FULL':
                        $this->logger->info("Starting scheduled full import");
                        $this->sinch->runSinchImport();
                        break;
                    case 'PRICE STOCK':
                        $this->logger->info("Starting scheduled stock price import");
                        $this->sinch->runStockPriceImport();
                        break;
                    default:
                        $this->logger->info("Unknown import type: " . $importType);
                        break;
                }

                $this->emulation->stopEnvironmentEmulation();
            } catch (Exception $e) {
                $this->logger->warning("Caught exception while running import: " . $e->getMessage());
            }
        }
    }
}
