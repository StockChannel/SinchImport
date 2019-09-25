<?php
/**
 * @author    Tigren Solutions <info@tigren.com>
 * @copyright Copyright (c) 2019 Tigren Solutions <https://www.tigren.com>. All rights reserved.
 * @license   Open Software License ("OSL") v. 3.0
 */

namespace SITC\Sinchimport\Observer\Indexer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SITC\Sinchimport\Helper\Data as HelperData;

/**
 * Class IndexTonerfinder
 * @package SITC\Sinchimport\Observer\Indexer
 */
class IndexTonerfinder implements ObserverInterface
{
    const TONERFINDER = 'sinchimport/general/index_tonerfinder';

    /**
     * @var HelperData
     */
    private $helperData;

    /**
     * IndexTonerfinder constructor.
     * @param HelperData $helperData
     */
    public function __construct(
        HelperData $helperData
    ){
        $this->helperData = $helperData;
    }

    /**
     * @param Observer $observer
     * @return $this|void
     */
    public function execute(Observer $observer)
    {
        if ($this->helperData->getStoreConfig(self::TONERFINDER)) {
            $this->helperData->insertCategoryIdForFinder();
        }
        return $this;
    }
}

