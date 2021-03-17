<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\Response\Http as HttpResponse;


class QueryBuilder {

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory 
     */
    private $collectionFactory;
    /**
     * @var \Magento\Framework\App\Response\Http
     */
    private $response;

    /**
     * @var \Zend\Log\Logger;
     */
    private $logger;


    public function __construct(CollectionFactory $collectionFactory, HttpResponse $response)
    {
        $this->collectionFactory = $collectionFactory;
        $this->response = $response;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $this->$this->logger = new \Zend\Log\Logger(); 
        $this->logger->addWriter($writer);
    }

    public function afterCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, $result, $containerConfig, $queryText)
    {
        $categoryCollection = $this->collectionFactory->create()->addAttributeToFilter('name', $queryText);

        if ($categoryCollection->getSize() > 0) {
            $category = $categoryCollection->getFirstItem(); //How often is the size of the collection > 1?? #getFirstItem() maybe not a good idea
            $this->logger->info("Collection size: " . strval($categoryCollection->getSize()));
            $this->logger->info("Category redirect to: " . strval($category->getId()) . "|" . $category->getName() . " for search term: " . $queryText);

            $this->response->setRedirect($category->getUrl())->sendResponse();
            return null; 
        }
        
        return $result;
    }
}