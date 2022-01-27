<?php

namespace SITC\Sinchimport\Cron;

use SITC\Sinchimport\Model\Sinch;

class Importfull
{
    /**
     * @var Sinch
     */
    private $sinch;
    
    /**
     * @param Sinch
     */
    public function __construct(
        Sinch $sinch
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
