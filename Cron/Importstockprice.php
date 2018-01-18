<?php

namespace SITC\Sinchimport\Cron;

class Importstockprice
{
    /**
     * @var \SITC\Sinchimport\Model\Sinch
     */
    private $sinch;
    
    /**
     * @param \SITC\Sinchimport\Model\Sinch
     */
    public function __construct(
        \SITC\Sinchimport\Model\Sinch $sinch
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
        $this->sinch->startCronStockPriceImport();
    }
}