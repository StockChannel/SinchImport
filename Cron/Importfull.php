<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Cron;

class Importfull
{
    /**
     * Holds the Sinch model
     * 
     * @var \Magebuzz\Sinchimport\Model\Sinch
     */
    private $_sinch;
    
    /**
     * Constructor
     * 
     * @param \Magebuzz\Sinchimport\Model\Sinch $sinch Sinch model
     */
    public function __construct(
        \Magebuzz\Sinchimport\Model\Sinch $sinch
    ) {
        $this->_sinch = $sinch;
    }
    
    /**
     * Cron job method to fetch new tickets
     *
     * @return void
     */
    public function execute()
    {
        $this->_sinch->startCronFullImport();
    }
}
