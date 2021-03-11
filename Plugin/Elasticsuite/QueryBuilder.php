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


    public function __construct(CollectionFactory $collectionFactory, HttpResponse $response)
    {
        $this->collectionFactory = $collectionFactory;
        $this->response = $response;
    }

    public function afterCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, $result, $containerConfig, $queryText)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);

        $categoryCollection = $this->collectionFactory->create()->addAttributeToFilter('name', $queryText);

        if ($categoryCollection->getSize() > 0) {
            $category = $categoryCollection->getFirstItem();
            $logger->info("Collection size: " . strval($categoryCollection->getSize()));
            $logger->info("Category redirect to: " . strval($category->getId()) . "|" . $category->getName() . " for search term: " . $queryText);
            $logger->info("Category URL: " . $category->getUrl());

            $this->response->setRedirect($category->getUrl())->sendResponse();
            return null;
        }
        
        return $result;
    }
}