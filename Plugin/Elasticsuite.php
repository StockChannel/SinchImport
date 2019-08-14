<?php
namespace SITC\Sinchimport\Plugin;
class Elasticsuite {
    /**
     * @var \SITC\Sinchimport\Helper\Data
     */
    private $helper;
    private $registry;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \Magento\Framework\Registry $registry
    ){
        $this->helper = $helper;
        $this->registry = $registry;
    }

    /**
     * Initialize product collection
     *
     * @param \Magento\Search\Model\SearchEngine $subject
     * @param \Magento\Framework\Api\Search\SearchCriteriaInterface $searchCriteria
     */
    public function beforeSearch($subject, $searchCriteria)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/test.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info(print_r($searchCriteria, true));
        return null;
    }
}