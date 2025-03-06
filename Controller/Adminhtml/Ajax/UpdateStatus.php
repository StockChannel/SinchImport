<?php

namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Sinch;

class UpdateStatus extends Action
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
    
    protected $_jsonEncoder;
    
    protected $sinch;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param \Magento\Framework\Serialize\Serializer\Json $jsonEncoder
     * @param Sinch $sinch
     * @param Logger $logger
     */
    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        \Magento\Framework\Serialize\Serializer\Json $jsonEncoder,
        Sinch $sinch,
        Logger $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->_jsonEncoder      = $jsonEncoder;
        $this->sinch             = $sinch;
        $this->_logger           = $logger;
    }
    
    /**
     * Category list suggestion based on already entered symbols
     *
     * @return Json
     */
    public function execute(): Json
    {
        $resultJson = $this->resultJsonFactory->create();
        $messageData = $this->sinch->getImportStatuses();
        return $resultJson->setJsonData($this->_jsonEncoder->serialize($messageData));
    }
    
    /**
     * Check if admin has permissions to visit related pages
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return true;
    }
}
