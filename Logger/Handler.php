<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Logger;

class Handler extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * Logging level
     *
     * @var int
     */
    protected $loggerType = Logger::INFO;

    /**
     * File name
     *
     * @var string
     */
    protected $fileName = '/var/log/sinch_import.log';
}
