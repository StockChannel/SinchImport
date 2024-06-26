<?php

namespace SITC\Sinchimport\Model\Import;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

abstract class AbstractImportSection {
    const LOG_PREFIX = "AbstractImportSection: ";
    const LOG_FILENAME = "unknown";

    /**
     * @var \Magento\Framework\App\ResourceConnection $resourceConn
     */
    protected $resourceConn;
    /**
     * @var Logger
     */
    protected $logger;
    /**
     * @var \Symfony\Component\Console\Output\ConsoleOutput
     */
    protected $output;

    /** @var mixed */
    protected $timingStep = [];

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output
    ){
        $this->resourceConn = $resourceConn;

        $writer = new StreamHandler(BP . "/var/log/sinch_" . static::LOG_FILENAME . ".log");
        $logger = new Logger(static::LOG_FILENAME);
        $logger->pushHandler($writer);
        $this->logger = $logger;
        $this->output = $output;
    }

    /**
     * @return float
     */
    protected function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }

    /**
     * @param string $table Table name
     * @return string Resolved table name
     */
    protected function getTableName($table)
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
    protected function startTimingStep($name)
    {
        $now = $this->microtime_float();
        $this->timingStep[] = [
            'start' => $now,
            'name' => $name,
            'end' => null
        ];
    }

    /**
     * Ends timing execution for the most recent step
     * @return void
     */
    protected function endTimingStep()
    {
        $now = $this->microtime_float();
        $this->timingStep[count($this->timingStep) - 1]['end'] = $now;
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
}