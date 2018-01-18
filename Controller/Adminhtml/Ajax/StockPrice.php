<?php

namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

class StockPrice extends \Magento\Backend\App\Action
{
    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var \Magento\Framework\View\LayoutFactory
     */
    protected $_layoutFactory;

    /**
     * Logging instance
     *
     * @var \SITC\Sinchimport\Logger\Logger
     */
    protected $_logger;

    protected $_jsonEncoder;

    protected $sinch;

    protected $_directory;

    /**
     * @param \Magento\Backend\App\Action\Context              $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Framework\View\LayoutFactory            $layoutFactory
     * @param \Magento\Framework\Json\EncoderInterface         $jsonEncoder
     * @param \SITC\Sinchimport\Logger\Logger              $logger
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\View\LayoutFactory $layoutFactory,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \SITC\Sinchimport\Model\Sinch $sinch,
        \SITC\Sinchimport\Logger\Logger $logger,
        \Magento\Framework\Filesystem\DirectoryList $directoryList
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->layoutFactory     = $layoutFactory;
        $this->_jsonEncoder      = $jsonEncoder;
        $this->sinch             = $sinch;
        $this->_logger           = $logger;
        $this->_directory        = $directoryList;
    }

    /**
     * Category list suggestion based on already entered symbols
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultJsonFactory->create();

        $this->_logger->info('Start Stock & Price Import');

        $rootDir = $this->_directory->getRoot() . '/';

        if (!$this->sinch->isImportNotRun()) {
            $result = [
                'success' => false,
                'message' => 'Import is running now! Please wait...',
                'reload' => !$this->sinch->isImportNotRun() && !empty($lastImportData) && $lastImportData['import_type'] == 'FULL'
            ];
        } else {
            
                exec(
                    "nohup php " . $rootDir
                    . "bin/magento sinch:import stockprice > /dev/null & echo $!",
                    $out
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
