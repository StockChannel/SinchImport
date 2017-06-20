<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

require __DIR__ . '/../../../../app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);

$objectManager = $bootstrap->getObjectManager();

$sinchModel = $objectManager->create('Magebuzz\Sinchimport\Model\Sinch');

$sinchModel->runSinchImport();