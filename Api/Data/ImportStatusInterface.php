<?php

namespace SITC\Sinchimport\Api\Data;

interface ImportStatusInterface {
    /**
     * Get import history ID
     * @return int
     */
    public function getId();

    /**
     * Get import start time
     * @return string
     */
    public function getStartTime();

    /**
     * Get import finish time
     * @return string|null
     */
    public function getFinishTime();

    /** 
     * Get the import type ("FULL" or "PRICE STOCK")
     * @return string
     */
    public function getImportType();

    /**
     * Get the number of products imported. Will be 0 if products have not been counted yet
     * @return int
     */
    public function getProductCount();

    /**
     * Get the global status
     * @return string
     */
    public function getGlobalStatus();

    /**
     * Get detailed status information
     * @return string
     */
    public function getDetailedStatus();

    /**
     * Get information on how this import was started ("CRON" or "MANUAL")
     * @return string
     */
    public function getRunType();

    /**
     * Get the error message, if any. Returns null if no error occurred
     * @return string|null
     */
    public function getErrorMsg();
}