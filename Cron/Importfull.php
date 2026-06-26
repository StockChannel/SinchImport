<?php

namespace SITC\Sinchimport\Cron;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Model\Sinch;

class Importfull
{
    /**
     * @var Sinch
     */
    private $sinch;

    /**
     * @param Sinch $sinch
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
    public function execute(): void
    {
        $this->sinch->startCronFullImport();
    }
}
