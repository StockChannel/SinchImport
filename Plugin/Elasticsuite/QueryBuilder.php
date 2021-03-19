<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\Response\Http as HttpResponse;
use Smile\ElasticsuiteCore\Client\Client;

class QueryBuilder
{

    const PRODUCT_INDEX_NAME = 'magento2_default_catalog_product';

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

    /**
     * @var \Smile\ElasticsuiteCore\Client\Client;
     */
    private $client;


    public function __construct(CollectionFactory $collectionFactory, HttpResponse $response, Client $client)
    {
        $this->collectionFactory = $collectionFactory;
        $this->response = $response;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
        $this->client = $client;
    }

    /**
     * Intercepts the QueryBuilders create method to perform any category redirects
     *
     * @param \Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject
     * @param \Smile\ElasticsuiteCore\Search\Request\QueryInterface $result
     * @param \Smile\ElasticsuiteCore\Search\Request\ContainerConfiguration $containerConfig
     * @param string $queryText
     *
     * @return \Smile\ElasticsuiteCore\Search\Request\QueryInterface
     */
    public function afterCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, $result, $containerConfig, $queryText)
    {
        $categoryCollection = $this->collectionFactory->create()->addAttributeToFilter('name', $queryText);

        // $mapping = $this->client->getMapping('magento2_default_catalog_product');

        // $mapping['magento2_default_catalog_product_20210319_010718']['mappings']['properties']['category']['properties']['name']['fields'] = [
        //     'stemmed' => [
        //         'type' => 'text',
        //         'analyzer' => 'english',
        //     ]
        // ];

        // $this->client->putMapping('magento2_default_catalog_product', $mapping['magento2_default_catalog_product_20210319_010718']['mappings']);

        // $mapping = $this->client->getMapping('magento2_default_catalog_product');

        // $this->logger->info("*** UPDATED MAPPING ***");
        // $this->logger->info(json_encode($mapping));

        if ($categoryCollection->getSize() < 1) {
            $analyzedQueryText = $this->getAnalyzedQueryText($queryText);
            $this->logger->info("Analyzed query text: " . json_encode($analyzedQueryText));

            $categoryCollection = $this->collectionFactory->create()->addAttributeToFilter('name', $analyzedQueryText);
        }

		// if ($categoryCollection->getSize() < 1) {
		// 	$this->logger->info("Pluralising query");
		// 	$queryText .= 's';
		// 	$categoryCollection = $this->collectionFactory->create()->addAttributeToFilter('name', $queryText);
		// }

        if ($categoryCollection->getSize() > 0) {
            $category = $categoryCollection->getFirstItem(); //How often is the size of the collection > 1? #getFirstItem() maybe not a good idea
            $this->logger->info("Collection size: " . strval($categoryCollection->getSize()));
            $this->logger->info("Category redirect to: " . strval($category->getId()) . "|" . $category->getName() . " for search term: " . $queryText);

            $this->response->setRedirect($category->getUrl())->sendResponse();
            return null;
        }

        $this->logger->info("No category redirect found");
        return $result;
    }

    /**
     * Analyses the query text and returns the analyzed query
     *
     * @param string $queryText
     *
     * @return string
     */
    private function getAnalyzedQueryText($queryText)
    {
        try {
            $analysis = $this->client->analyze(
                ['body' => ['text' => $queryText, 'analyzer' => 'english']]
            )['tokens'];
        } catch (\Exception $e) {
            return "";
        }
        $analysis = array_column($analysis, 'token');
        $imploded = implode(" ", $analysis);
        return $imploded;
    }
}
