<?php

namespace SITC\Sinchimport\Model\Import;

abstract class AbstractImportSection {
    /**
     * @var \Magento\Framework\App\ResourceConnection $resourceConn
     */
    protected $resourceConn;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn
    ){
        $this->resourceConn = $resourceConn;
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
}