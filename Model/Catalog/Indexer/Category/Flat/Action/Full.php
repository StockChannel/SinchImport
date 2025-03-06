<?php

namespace SITC\Sinchimport\Model\Catalog\Indexer\Category\Flat\Action;

use Magento\Catalog\Model\Category;
use Magento\Store\Model\Store;

class Full extends \Magento\Catalog\Model\Indexer\Category\Flat\Action\Full
{
    public function populateFlatTables(array $stores)
    {
        $rootId = Category::TREE_ROOT_ID;
        $categories = [];
        $categoriesIds = [];
        /* @var $store Store */
        foreach ($stores as $store) {
            if (!isset($categories[$store->getRootCategoryId()])) {
                $select = $this->connection->select()->from(
                    $this->connection->getTableName($this->getTableName('catalog_category_entity'))
                )->where(
                    'path = ?',
                    (string)$rootId
                );

                //This is the only change made by this code (just the selection of root categories)
                $selectRootCats = $this->connection->select()->from(
                    $this->connection->getTableName($this->getTableName('catalog_category_entity')),
                    'entity_id'
                )->where(
                    'level = 1'
                );
                $rootCats = $this->connection->fetchAll($selectRootCats);
                foreach ($rootCats as $rootCat) {
                    $select->orWhere(
                        'path = ?',
                        "{$rootId}/{$rootCat['entity_id']}"
                    )->orWhere(
                        'path LIKE ?',
                        "{$rootId}/{$rootCat['entity_id']}/%"
                    );
                }
                $categories[$store->getRootCategoryId()] = $this->connection->fetchAll($select);
                $categoriesIds[$store->getRootCategoryId()] = [];
                foreach ($categories[$store->getRootCategoryId()] as $category) {
                    $categoriesIds[$store->getRootCategoryId()][] = $category['entity_id'];
                }
            }
            /**
             * @TODO Do something with chunks
             */
            $categoriesIdsChunks = array_chunk($categoriesIds[$store->getRootCategoryId()], 500);
            foreach ($categoriesIdsChunks as $categoriesIdsChunk) {
                $attributesData = $this->getAttributeValues($categoriesIdsChunk, $store->getId());
                $data = [];
                foreach ($categories[$store->getRootCategoryId()] as $category) {
                    if (!isset($attributesData[$category[$this->categoryMetadata->getLinkField()]])) {
                        continue;
                    }
                    $category['store_id'] = $store->getId();
                    $data[] = $this->prepareValuesToInsert(
                        array_merge($category, $attributesData[$category[$this->categoryMetadata->getLinkField()]])
                    );
                }
                $this->connection->insertMultiple(
                    $this->addTemporaryTableSuffix($this->getMainStoreTable($store->getId())),
                    $data
                );
            }
        }

        return $this;
    }
}
