<?php

namespace SITC\Sinchimport\Observer\PostImport;

use Magento\Framework\Event\ObserverInterface;
use SITC\Sinchimport\Helper\Data;

class SendSuccessEmail implements ObserverInterface
{
    private Data $helper;

    public function __construct(Data $helper)
    {
        $this->helper = $helper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->helper->sendSuccessEmail();
    }
}
