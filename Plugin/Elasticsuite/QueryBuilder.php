<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Smile\ElasticsuiteCore\Client\Client;
use \Magento\Framework\App\ResourceConnection;

class QueryBuilder
{

    const CATEGORY_INDEX_NAME = 'magento2_default_catalog_category';
	const METHOD = 'mysql';

    /**
     * @var \Magento\Catalog\Api\CategoryRepositoryInterface
     */
    private $categoryRespository;

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

	private $resourceConnection;

	private $connection;

	private $categoryTableVarchar;
	private $categoryTable;
	private $eavTable;

    public function __construct(
		CategoryRepositoryInterface $categoryRespository, 
		HttpResponse $response, 
		Client $client, 
		ResourceConnection $resourceConnection
	)
    {
        $this->categoryRespository = $categoryRespository;
        $this->response = $response;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
        $this->client = $client;
		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->categoryTableVarchar = $this->connection->getTableName('catalog_category_entity_varchar');
		$this->categoryTable = $this->connection->getTableName('catalog_category_entity');
		$this->eavTable = $this->connection->getTableName('eav_attribute');
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
		$start = microtime(true);
		if (self::METHOD == 'mysql') {
			$pluralQueryText = $queryText . 's';
			$catId = $this->connection->fetchOne("
			SELECT ccev.entity_id FROM {$this->categoryTableVarchar} ccev 
			JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
			JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id
			WHERE ccev.value = :queryText OR ccev.value = :pluralQueryText",
			['queryText' => $queryText, 'pluralQueryText' => $pluralQueryText]);

			if (empty($catId)) {
				$this->logger->info("No category redirect found");
				return $result;
			}
	
			$category = $this->categoryRespository->get((int)$catId);
			$this->logger->info("Category redirect to: " . strval($category->getId()) . "|" . $category->getName() . " for search term: " . $queryText);
	
			$this->response->setRedirect($category->getUrl())->sendResponse();
	
			$elapsed = abs(microtime(true) - $start) * 1000;
			$this->logger->info("Total execution time: " . strval($elapsed) . "ms");
			return null;
		} else if (self::METHOD == 'elasticsearch') {
			$analyzedQueryText = $this->getAnalyzedQueryText($queryText);
            $this->logger->info("Analyzed query text: " . $analyzedQueryText);
			$searchReq = [
				'index' => self::CATEGORY_INDEX_NAME,
				'body' => [
					'size' => 1,
					'query' => [
					  'term' => [
						'name' => $analyzedQueryText
					  ]
					]
				  ]
			];
			$res = $this->client->search($searchReq);
			$catId = $res['hits']['hits'][0]['_id'];
			$category = $this->categoryRespository->get((int)$catId);
			$this->logger->info("Category redirect to: " . strval($category->getId()) . "|" . $category->getName() . " for search term: " . $queryText);
			$this->response->setRedirect($category->getUrl())->sendResponse();

			$elapsed = abs(microtime(true) - $start) * 1000;
			$this->logger->info("Total execution time: " . strval($elapsed) . "ms");
			return null;
		}
    }

    /**
     * Analyses the query text and returns the analyzed query
     *
     * @param string $queryText
     *
     * @return string
     */
    private function getAnalyzedQueryText($queryText) : string
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
