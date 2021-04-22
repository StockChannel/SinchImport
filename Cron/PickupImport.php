<?php

namespace SITC\Sinchimport\Cron;

/**
 * Pickup new import runs from the database (triggered by admin panel), and start them
 */
class PickupImport
{
    /** @var \Magento\Framework\App\ResourceConnection $resourceConn */
    private $resourceConn;

    /** @var \SITC\Sinchimport\Model\Sinch $sinch */
    private $sinch;

    /** @var \SITC\Sinchimport\Logger\Logger $logger */
    private $logger;

    //Table name
    private $importStatusTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Model\Sinch $sinch,
        \SITC\Sinchimport\Logger\Logger $logger
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
            } catch (\Exception $e) {
                $this->logger->warn("Caught exception while running import: " . $e->getMessage());
            }
        }
    }
}
