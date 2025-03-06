<?php
namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Json\EncoderInterface;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Sinch;

class Index extends Action
{
    /** @var JsonFactory $resultJsonFactory */
    protected $resultJsonFactory;

    /** @var EncoderInterface $_jsonEncoder */
    protected $_jsonEncoder;

    /** @var Sinch $sinch */
    protected $sinch;

    /** @var Logger $logger */
    protected $logger;

    /** @var Data $helper */
    private $helper;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        EncoderInterface $jsonEncoder,
        Sinch $sinch,
        Logger $logger,
        Data $helper
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
        * @var Json $resultJson
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
