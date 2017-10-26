<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Cron;

class Importfull
{
    /**
     * @var \Magebuzz\Sinchimport\Model\Sinch
     */
    private $sinch;
    
    /**
     * @param \Magebuzz\Sinchimport\Model\Sinch
     */
    public function __construct(
        \Magebuzz\Sinchimport\Model\Sinch $sinch
    ) {
        $this->sinch = $sinch;
    }
    
    /**
     * Cron job method to fetch new tickets
     *
     * @return void
     */
    public function execute()
    {
        $this->sinch->startCronFullImport();
    }
}
