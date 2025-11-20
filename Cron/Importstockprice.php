<?php

namespace SITC\Sinchimport\Cron;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Model\Sinch;

class Importstockprice
{
    /**
     * @var Sinch
     */
    private $sinch;

    /**
     * @param Sinch $sinch
     * @param Emulation $emulation
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Sinch $sinch,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager
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
        $this->emulation->startEnvironmentEmulation($this->storeManager->getDefaultStoreView()->getId(), Area::AREA_ADMINHTML);

        $this->sinch->startCronStockPriceImport();

        $this->emulation->stopEnvironmentEmulation();
    }
}
