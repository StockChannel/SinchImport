<?php

namespace SITC\Sinchimport\Model\Api\Data;

class ImportStatus implements \SITC\Sinchimport\Api\Data\ImportStatusInterface {
    private $id;
    private $startTime;
    private $endTime = null;
    private $type = "FULL";
    private $numProds = 0;
    private $globalStatus = "";
    private $detailStatus = "";
    private $runType = "MANUAL";
    private $errorMsg = null;

    public function __construct($data){
        if(!isset($data['id']) || !isset($data['start_import']) || !isset($data['finish_import']) ||
            !isset($data['import_type']) || !isset($data['global_status_import']) || !isset($data['detail_status_import']) ||
            !isset($data['import_run_type'])) {
            throw new \LogicException('A required field was missing while creating an ImportStatus model');
        }

        $this->id = $data['id'];
        $this->startTime = $data['start_import'];
        $this->type = $data['import_type'];
        $this->globalStatus = $data['global_status_import'];
        $this->detailStatus = $data['detail_status_import'];
        $this->runType = $data['import_run_type'];

        if($data['finish_import'] != "0000-00-00 00:00:00") {
            $this->endTime = $data['finish_import'];
        }
        
        if(isset($data['number_of_products'])) {
            $this->numProds = $data['number_of_products'];
        }
        
        if(!empty($data['error_report_message'])) {
            $this->errorMsg = $data['error_report_message'];
        }
    }

    /**
     * Get import history ID
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get import start time
     * @return string
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * Get import finish time
     * @return string|null
     */
    public function getFinishTime()
    {
        return $this->endTime;
    }

    /** 
     * Get the import type ("FULL" or "PRICE STOCK")
     * @return string
     */
    public function getImportType()
    {
        return $this->type;
    }

    /**
     * Get the number of products imported. Will be 0 if products have not been counted yet
     * @return int
     */
    public function getProductCount()
    {
        return $this->numProds;
    }

    /**
     * Get the global status
     * @return string
     */
    public function getGlobalStatus()
    {
        return $this->globalStatus;
    }

    /**
     * Get detailed status information
     * @return string
     */
    public function getDetailedStatus()
    {
        return $this->detailStatus;
    }

    /**
     * Get information on how this import was started ("CRON" or "MANUAL")
     * @return string
     */
    public function getRunType()
    {
        return $this->runType;
    }

    /**
     * Get the error message, if any. Returns null if no error occurred
     * @return string|null
     */
    public function getErrorMsg()
    {
        if(!empty($this->errorMsg)) {
            return $this->errorMsg;
        }
        return null;
    }
}