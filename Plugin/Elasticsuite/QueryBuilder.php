<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;

class QueryBuilder
{

	CONST PRICE_REGEXP = "/(?(DEFINE)(?<price>[0-9]+(?:.[0-9]+)?)(?<cur>(?:\p{Sc}|[A-Z]{3})\s?))(?<query>.+?)\s+(?J:(?:below|under|(?:cheaper|less)\sthan)\s+(?&cur)?(?<below>(?&price))|(?:between|from)?\s*(?&cur)?(?<above>(?&price))\s*(?:and|to|-)\s*(?&cur)?(?<below>(?&price)))/";
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

		$priceFilter = $this->getPriceFiltersFromQuery($queryText);
		$filterParams = '';
		if ($priceFilter !== false) {
			$queryText = $priceFilter['query'];
			$below = $priceFilter['below'];
			$above = $priceFilter['above'];
			if ($below != -1)
				$filterParams = "?price={$above}-{$below}";
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
		$url = $category->getUrl() . $filterParams;

		$this->response->setRedirect($url)->sendResponse(); 

		$elapsed = abs(microtime(true) - $start) * 1000;
		$this->logger->info("Total execution time: " . strval($elapsed) . "ms");
		return null;
    }


	/**
	 * Get price bounds from query text
	 *
	 * @param string $queryText
	 *
	 * @return array|bool
	 */
	private function getPriceFiltersFromQuery($queryText)
	{
		$matches = [];
		if (preg_match_all(self::PRICE_REGEXP, $queryText, $matches, PREG_SET_ORDER)) {
			$matches = $matches[0];
			$query = isset($matches['query']) ? $matches['query'] : '';
			$below = isset($matches['below']) ? $matches['below'] : -1;
			$above = isset($matches['above']) ? $matches['above'] : 0;

			$res = [
				'query' => $query,
				'below' => $below,
				'above' => $above,
			];

			return $res;
		}

		return false;
	}
}
