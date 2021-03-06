<?php

namespace SITC\Sinchimport\Model\Api;

class ImportService implements \SITC\Sinchimport\Api\ImportInterface {
    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;
    /** @var \Magento\Framework\App\ResourceConnection $resourceConn */
    private $resourceConn;
    /** @var \SITC\Sinchimport\Model\Api\Data\ImportStatusFactory */
    private $importStatusFactory;

    /** @var string $importStatusTable The prepared name of the import status table */
    private $importStatusTable;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Model\Api\Data\ImportStatusFactory $importStatusFactory
    ){
        $this->helper = $helper;
        $this->resourceConn = $resourceConn;
        $this->importStatusFactory = $importStatusFactory;

        $this->importStatusTable = $this->resourceConn->getTableName('sinch_import_status_statistic');
    }

    /**
     * {@inheritdoc}
     */
    public function getLatestStatus()
    {
        $results = $this->resourceConn->getConnection()->fetchAll(
            "SELECT * FROM {$this->importStatusTable} ORDER BY id DESC LIMIT 1"
        );
        if(count($results) >= 1) {
            return $this->importStatusFactory->create(['data' => $results[0]]);
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllStatuses()
    {
        $rawResults = $this->resourceConn->getConnection()->fetchAll(
            "SELECT * FROM {$this->importStatusTable} ORDER BY id DESC"
        );
        $results = [];
        foreach($rawResults as $result) {
            $results[] = $this->importStatusFactory->create(['data' => $result]);
        }
        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleImport($importType)
    {
        if($importType !== "FULL" && $importType !== "PRICE STOCK") {
            throw new \LogicException("Invalid import type: {$importType}. Valid types are: 'FULL', 'PRICE STOCK'");
        }
        $this->helper->scheduleImport($importType);
    }
}