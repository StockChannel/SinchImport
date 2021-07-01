<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite\Autocomplete;

use Magento\Framework\App\ResourceConnection;
use Magento\Search\Model\Autocomplete\ItemInterface;
use SITC\Sinchimport\Helper\Data;
use Magento\Search\Model\QueryFactory;
use Magento\Search\Model\Autocomplete\ItemFactory;
use Smile\ElasticsuiteCore\Model\Autocomplete\Terms\DataProvider as ESDataProvider;
use Smile\ElasticsuiteCore\Helper\Autocomplete as ESConfigurationHelper;
use Zend\Log\Logger;
use Zend\Log\Writer\Stream;

class TermsDataProvider {

	const AUTOCOMPLETE_TYPE = 'term';

	private $resourceConnection;
	private $connection;
	private $helper;
	private $itemFactory;
	private $queryFactory;
	private $logger;
	private $configurationHelper;


	private $categoryTable;
	private $categoryTableVarchar;
	private $eavTable;

	public function __construct(
		ResourceConnection $resourceConnection,
		Data $helper,
		ItemFactory $itemFactory,
		QueryFactory $queryFactory,
		ESConfigurationHelper $configurationHelper
	){
		$writer = new Stream(BP . '/var/log/joe_search_stuff.log');
		$this->logger = new Logger();
		$this->logger->addWriter($writer);

		$this->resourceConnection = $resourceConnection;
		$this->connection = $this->resourceConnection->getConnection();
		$this->helper = $helper;
		$this->itemFactory = $itemFactory;
		$this->queryFactory = $queryFactory;
		$this->configurationHelper = $configurationHelper;
		$this->categoryTableVarchar = $this->connection->getTableName('catalog_category_entity_varchar');
		$this->categoryTable = $this->connection->getTableName('catalog_category_entity');
		$this->eavTable = $this->connection->getTableName('eav_attribute');
	}

	/**
	 * Interceptor for displaying autocomplete terms in quick search
	 *
	 * @param ESDataProvider $subject
	 * @param ItemInterface[] $result The return of the intercepted method
	 *
	 * @return ItemInterface[]
	 */
	public function afterGetItems(ESDataProvider $subject, array $result): array
	{
		if ($this->helper->experimentalSearchEnabled()) {

			$result = $this->getSuggestions($this->queryFactory->get()->getQueryText());
			$this->logger->info($result);

		}
		return $result;
	}

	private function getCategoryIdFromQueryText(string $queryText) : string
	{
		return $this->connection->fetchOne("SELECT cce.entity_id FROM {$this->categoryTableVarchar} ccev 
                JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
                JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id 
                WHERE ccev.value LIKE '{$queryText}'");
	}

	private function getCategoryNameFromId(int $catId) : string
	{
		return $this->connection->fetchOne("SELECT ccev.value FROM {$this->categoryTableVarchar} ccev 
                JOIN {$this->eavTable} ea ON ea.attribute_id = ccev.attribute_id AND ea.attribute_code = 'name'
                JOIN {$this->categoryTable} cce ON cce.attribute_set_id = ea.entity_type_id AND cce.entity_id = ccev.entity_id 
                WHERE cce.entity_id = {$catId}");
	}

	private function getChildCategories(int $parentCatId) : array
	{
		return $this->connection->fetchAll("SELECT entity_id FROM {$this->categoryTable}
		WHERE parent_id = {$parentCatId}");
	}

	private function getSuggestions(string $queryText) : array
	{
		$catId = $this->getCategoryIdFromQueryText($queryText);
		if (empty($catId)) {
			$this->logger->info("Did not determine cat from query text");
			return [];
		}

		$childCatIds = $this->getChildCategories($catId);
		$suggestions = [];
		$pageSize = $this->getResultsPageSize();

		$i = 0;
		foreach ($childCatIds as $childCatId) {
			if ($i == $pageSize)
				break;

			$childCatId = $childCatId['entity_id'];
			$catName = $this->getCategoryNameFromId($childCatId);
			$item = $this->itemFactory->create([
				'title' => $catName,
				'type' => self::AUTOCOMPLETE_TYPE
			]);
			$suggestions[] = $item;
			$i++;
		}

		return $suggestions;
	}

	private function getResultsPageSize() : int
	{
		return $this->configurationHelper->getMaxSize(self::AUTOCOMPLETE_TYPE);
	}
}