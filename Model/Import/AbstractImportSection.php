<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

abstract class AbstractImportSection {
    const LOG_PREFIX = "AbstractImportSection: ";
    const LOG_FILENAME = "unknown";

    /**
     * @var ResourceConnection $resourceConn
     */
    protected $resourceConn;
    /**
     * @var \Zend\Log\Logger
     */
    protected $logger;
    /**
     * @var ConsoleOutput
     */
    protected $output;
    /** @var Download */
    protected $dlHelper;

    /** @var mixed */
    protected $timingStep = [];

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

    public abstract function parse();
}