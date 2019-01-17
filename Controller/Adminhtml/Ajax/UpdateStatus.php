<?php

namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

/**
 * Class UpdateStatus
 * @package SITC\Sinchimport\Controller\Adminhtml\Ajax
 */
class UpdateStatus extends \Magento\Backend\App\Action
{
    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * Logging instance
     *
     * @var \SITC\Sinchimport\Logger\Logger
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\Json\EncoderInterface
     */
    protected $_jsonEncoder;

    /**
     * @var \SITC\Sinchimport\Model\Sinch
     */
    protected $sinch;

    /**
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Magento\Framework\Json\EncoderInterface $jsonEncoder
     * @param \SITC\Sinchimport\Logger\Logger $logger
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \SITC\Sinchimport\Model\Sinch $sinch,
        \SITC\Sinchimport\Logger\Logger $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->_jsonEncoder = $jsonEncoder;
        $this->sinch = $sinch;
        $this->_logger = $logger;
    }

    /**
     * Category list suggestion based on already entered symbols
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        $messageData = $this->sinch->getImportStatuses();
        return $resultJson->setJsonData($this->_jsonEncoder->encode($messageData));
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
