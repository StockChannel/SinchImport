<?php

namespace SITC\Sinchimport\Model\Import;

abstract class AbstractImportSection {
    const LOG_PREFIX = "AbstractImportSection: ";
    const LOG_FILENAME = "unknown";

    /**
     * @var \Magento\Framework\App\ResourceConnection $resourceConn
     */
    protected $resourceConn;
    /**
     * @var \Zend\Log\Logger
     */
    protected $logger;
    /**
     * @var \Symfony\Component\Console\Output\ConsoleOutput
     */
    protected $output;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output
    ){
        $this->resourceConn = $resourceConn;

        $writer = new \Zend\Log\Writer\Stream(BP . "/var/log/sinch_" . self::LOG_FILENAME . ".log");
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
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

    protected function log($msg)
    {
        $this->output->writeln(self::LOG_PREFIX . $msg);
        $this->logger->info(self::LOG_PREFIX . $msg);
    }
}