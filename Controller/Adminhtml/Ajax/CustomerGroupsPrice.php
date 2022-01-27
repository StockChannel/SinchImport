<?php

namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Json\EncoderInterface;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Sinch;

/**
 * Class CustomerGroupsPrice
 * @package SITC\Sinchimport\Controller\Adminhtml\Ajax
 */
class CustomerGroupsPrice extends Action
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * Logging instance
     *
     * @var Logger
     */
    protected $_logger;

    /**
     * @var EncoderInterface
     */
    protected $_jsonEncoder;

    /**
     * @var Sinch
     */
    protected $sinch;

    /**
     * @var DirectoryList
     */
    protected $_directory;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param EncoderInterface $jsonEncoder
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        EncoderInterface $jsonEncoder,
        Sinch $sinch,
        Logger $logger,
        DirectoryList $directoryList
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->_jsonEncoder = $jsonEncoder;
        $this->sinch = $sinch;
        $this->_logger = $logger->withName("CustomerGroupPrice");
        $this->_directory = $directoryList;
    }

    /**
     * Stock Price
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();

        $this->_logger->info('Start Customer Groups & Price Import');

        $rootDir = $this->_directory->getRoot() . '/';

        if (!$this->sinch->canImport()) {
            $result = [
                'success' => false,
                'message' => 'Import is running now! Please wait...',
                'reload' => !$this->sinch->canImport() && !empty($lastImportData) && $lastImportData['import_type'] == 'FULL'
            ];
        } else {
            exec(
                "nohup php " . $rootDir
                . "bin/magento sinch:import customergroupsprice > /dev/null & echo $!"
            );


            $result = [
                'success' => true,
                'message' => '',
                'reload' => false
            ];
        }

        return $resultJson->setJsonData($this->_jsonEncoder->encode($result));
    }

    /**
     * Check if admin has permissions to visit related pages
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return true;
    }
}
