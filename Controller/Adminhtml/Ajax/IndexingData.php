<?php

namespace SITC\Sinchimport\Controller\Adminhtml\Ajax;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Json\EncoderInterface;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Sinch;

class IndexingData extends Action
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
    
    protected $_directory;
    
    /**
     * @param Context              $context
     * @param JsonFactory $resultJsonFactory
     * @param EncoderInterface         $jsonEncoder
     * @param Logger                  $logger
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
        $this->_jsonEncoder      = $jsonEncoder;
        $this->sinch             = $sinch;
        $this->_logger           = $logger;
        $this->_directory        = $directoryList;
    }
    
    /**
     * Index data
     */
    public function execute()
    {
        $this->_logger->info('Start Index process and URL generation');

        $resultJson = $this->resultJsonFactory->create();
        
        $rootDir = $this->_directory->getRoot() . '/';
        
    
            exec(
                "nohup php " . $rootDir
                . "bin/magento sinch:url:generate > /dev/null & echo $!"
            );
        
        
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
