<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Controller\Adminhtml\Ajax;

class Index extends \Magento\Backend\App\Action
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
     * @var \Magebuzz\Sinchimport\Logger\Logger
     */
    protected $_logger;

    protected $_jsonEncoder;

    protected $_sinchFactory;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Framework\View\LayoutFactory $layoutFactory
     * @param \Magento\Framework\Json\EncoderInterface $jsonEncoder
     * @param \Magebuzz\Sinchimport\Logger\Logger $logger
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\View\LayoutFactory $layoutFactory,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \Magebuzz\Sinchimport\Model\SinchFactory $sinchFactory,
        \Magebuzz\Sinchimport\Logger\Logger $logger
    ) {
        parent::__construct($context, $resultJsonFactory, $layoutFactory);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->layoutFactory = $layoutFactory;
        $this->_jsonEncoder = $jsonEncoder;
        $this->_sinchFactory = $sinchFactory;
        $this->_logger = $logger;
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

        $sichModel = $this->_sinchFactory->create();

        $this->_logger->info('Start Sinch Import');

        echo "Start Import <br>";

        //$sichModel->runSinchImport();

        $dir = dirname(__FILE__);
        $php_run_string_array = explode(';', $sichModel->php_run_strings);
        foreach($php_run_string_array as $php_run_string){
            exec("nohup ".$php_run_string." ".$dir."/../../../start_ajax_import.php > /dev/null & echo $!", $out);
            sleep(1);
            if (($out[0] > 0) && !$sichModel->isImportNotRun()){
                break;
            }
        }

        echo "Finish Import<br>";

        $result = ['success' => true];

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
