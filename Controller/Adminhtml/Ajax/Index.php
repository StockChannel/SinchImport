<?php
namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

class Index extends \Magento\Backend\App\Action
{
    /** @var \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory */
    protected $resultJsonFactory;

    /** @var \Magento\Framework\Json\EncoderInterface $_jsonEncoder */
    protected $_jsonEncoder;

    /** @var \SITC\Sinchimport\Model\Sinch $sinch */
    protected $sinch;

    /** @var \SITC\Sinchimport\Logger\Logger $logger */
    protected $logger;

    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \SITC\Sinchimport\Model\Sinch $sinch,
        \SITC\Sinchimport\Logger\Logger $logger,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->_jsonEncoder = $jsonEncoder;
        $this->sinch = $sinch;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    public function execute()
    {
        $this->logger->info('Schedule Full Import');

        /**
        * @var \Magento\Framework\Controller\Result\Json $resultJson
        */
        $resultJson = $this->resultJsonFactory->create();
        $lastImportData = $this->sinch->getDataOfLatestImport();

        if (!$this->sinch->canImport()) {
            $result = [
                'success' => false,
                'message' => 'Import is running now! Please wait...',
                'reload' => !$this->sinch->canImport() && !empty($lastImportData) && $lastImportData['import_type'] == 'PRICE STOCK'
            ];
        } else {
            $this->helper->scheduleImport('FULL');
            $result = [
                'success' => true,
                'message' => 'Full import has been scheduled. It may take up to 60 seconds to begin',
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
