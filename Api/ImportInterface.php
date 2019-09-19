<?php

namespace SITC\Sinchimport\Api;

interface ImportInterface {
    /**
     * Returns the status information for the most recent import
     * 
     * @api
     * @return mixed
     */
    public function getLatestStatus();

    /**
     * Returns the status information for all imports in history
     * 
     * @api
     * @return mixed
     */
    public function getAllStatuses();

    /**
     * Schedules an import to start as soon as possible
     * 
     * @api
     * @param string $importType The type of import to schedule, accepts "full" or "stockprice"
     * @return bool Whether the import was scheduled successfully
     */
    public function scheduleImport($importType);
}