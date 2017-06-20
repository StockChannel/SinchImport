<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */
namespace Magebuzz\Sinchimport\Cron;

class Importstockprice
{
    /**
     * @var \Magebuzz\Sinchimport\Model\MailFactory
     */
    private $_sinchFactory;

    /**
     * @param \Magebuzz\Sinchimport\Helper\Mail $_mailHelper
     * @param \Magebuzz\Sinchimport\Model\MailFactory
     */
    public function __construct(
        \Magebuzz\Sinchimport\Model\SinchFactory $sinchFactory
    ) {
        $this->_sinchFactory = $sinchFactory;
    }

    /**
     * Cron job method to fetch new tickets
     *
     * @return void
     */
    public function execute()
    {
        $sinchModel = $this->_sinchFactory->create();
        $sinchModel->startCronStockPriceImport();
    }
}