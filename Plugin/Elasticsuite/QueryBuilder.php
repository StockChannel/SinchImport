<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;

class QueryBuilder
{
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

	private $resourceConnection;

	private $connection;

	private $categoryTableVarchar;
	private $categoryTable;
	private $eavTable;

	/**
	 *
	 * @var \SITC\Sinchimport\Helper\Data
	 */
	private $helper;

    public function __construct(
		CategoryRepositoryInterface $categoryRespository, 
		HttpResponse $response, 
		ResourceConnection $resourceConnection,
		Data $helper
	)
    {
        $this->categoryRespository = $categoryRespository;
        $this->response = $response;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->categoryTableVarchar = $this->connection->getTableName('catalog_category_entity_varchar');
		$this->categoryTable = $this->connection->getTableName('catalog_category_entity');
		$this->eavTable = $this->connection->getTableName('eav_attribute');
		$this->helper = $helper;
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
		if (!$this->helper->experimentalSearchEnabled()) {
			return $result;
		}

		$start = microtime(true);
		$pluralQueryText = $queryText . 's';

		$catId = $this->connection->fetchOne("SELECT ccev.entity_id FROM {$this->categoryTableVarchar} ccev 
		JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
		JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id
		WHERE ccev.value = :queryText OR ccev.value = :pluralQueryText",
		['queryText' => $queryText, 'pluralQueryText' => $pluralQueryText]);

		if (empty($catId)) {
			return $result;
		}

		$category = $this->categoryRespository->get((int)$catId);

		$this->response->setRedirect($category->getUrl())->sendResponse(); //This redirects to the non-SEO cat URL

		$elapsed = abs(microtime(true) - $start) * 1000;
		$this->logger->info("Total execution time: " . strval($elapsed) . "ms");
		return null;
    }
}
