<?php

namespace SITC\Sinchimport\Api;

interface ImportInterface {
    /**
     * Returns the status information for the most recent import
     * 
     * @api
     * @return \SITC\Sinchimport\Api\Data\ImportStatusInterface|null
     */
    public function getLatestStatus();

    /**
     * Returns the status information for all imports in history
     * 
     * @api
     * @return \SITC\Sinchimport\Api\Data\ImportStatusInterface[]
     */
    public function getAllStatuses();

    /**
     * Schedules an import to start as soon as possible. Valid import types are "FULL" and "PRICE STOCK"
     * 
     * @api
     * @param string $importType The type of import to schedule
     * @return void
     */
    public function scheduleImport($importType);
}