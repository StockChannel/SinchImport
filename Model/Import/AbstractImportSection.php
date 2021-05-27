<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

abstract class AbstractImportSection {
    const LOG_PREFIX = "AbstractImportSection: ";
    const LOG_FILENAME = "unknown";

    protected ResourceConnection $resourceConn;
    protected \Zend\Log\Logger $logger;
    protected ConsoleOutput $output;
    protected Download $dlHelper;

    /** @var mixed */
    protected $timingStep = [];

    private string $statusTable;

    public function __construct(
        ResourceConnection $resourceConn,
        ConsoleOutput $output,
        Download $downloadHelper
    ){
        $this->resourceConn = $resourceConn;

        $writer = new \Zend\Log\Writer\Stream(BP . "/var/log/sinch_" . static::LOG_FILENAME . ".log");
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
        $this->output = $output;
        $this->dlHelper = $downloadHelper;

        $this->statusTable = $this->getTableName('sinch_import_status');
    }

    /**
     * @return float
     */
    protected function microtime_float(): float
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * @return AdapterInterface
     */
    protected function getConnection(): AdapterInterface
    {
        return $this->resourceConn->getConnection(ResourceConnection::DEFAULT_CONNECTION);
    }

    /**
     * @param string $table Table name
     * @return string Resolved table name
     */
    protected function getTableName(string $table): string
    {
        return $this->resourceConn->getTableName($table);
    }

    protected function log($msg, $print = true)
    {
        if($print){
            $this->output->writeln(static::LOG_PREFIX . $msg);
        }
        $this->logger->info(static::LOG_PREFIX . $msg);
    }

    /**
     * Start timing execution time (not intended for nested timing)
     * @param string $name A name to describe what occurs in the step
     * @return void
     */
    protected function startTimingStep(string $name)
    {
        $this->log("Timing Step: " . $name);
        $now = $this->microtime_float();
        $this->timingStep[] = [
            'start' => $now,
            'name' => $name,
            'end' => null
        ];
        //Insert into the import status table so the admin panel can see the import progressing
        $this->getConnection()->query(
            "INSERT INTO {$this->statusTable} (message, finished) VALUES(:msg, 0)",
            [":msg" => static::LOG_PREFIX . $name]
        );
    }

    /**
     * Ends timing execution for the most recent step
     * @return void
     */
    protected function endTimingStep()
    {
        $now = $this->microtime_float();
        $endedStepIdx = count($this->timingStep) - 1;
        $this->timingStep[$endedStepIdx]['end'] = $now;
        $this->getConnection()->query(
            "INSERT INTO {$this->statusTable} (message, finished) VALUES(:msg, 1)
                    ON DUPLICATE KEY UPDATE finished = VALUES(finished)",
            [":msg" => static::LOG_PREFIX . $this->timingStep[$endedStepIdx]['name']]
        );
    }

    /**
     * Print timing information created by startTimingStep and endTimingStep
     * @return void
     */
    protected function timingPrint()
    {
        $totalElapsed = 0.0;
        foreach ($this->timingStep as $timeStep) {
            if (empty($timeStep['end'])) {
                continue;
            }
            $totalElapsed += $timeStep['end'] - $timeStep['start'];
            $elapsed = number_format($timeStep['end'] - $timeStep['start'], 2);
            $this->log("{$timeStep['name']} => {$elapsed} seconds");
        }
        $elapsed = number_format($totalElapsed, 2);
        $this->log("Took {$elapsed} seconds total");
    }

    public abstract function parse();

    /**
     * Get the filenames required for this import section
     * @return array filenames needed to successfully run parse
     */
    public abstract function getRequiredFiles(): array;

    /**
     * Get whether we have the files needed for this import section to run
     * @return bool
     */
    public function haveRequiredFiles(): bool
    {
        foreach ($this->getRequiredFiles() as $file) {
            if (!$this->dlHelper->validateFile($file)) {
                return false;
            }
        }
        return true;
    }
}