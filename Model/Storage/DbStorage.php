<?php
namespace SITC\Sinchimport\Model\Storage;

use Magento\Framework\DB\Select;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;

class DbStorage extends \Magento\UrlRewrite\Model\Storage\DbStorage
{
    private function deleteOldUrls(array $urls)
    {
        $oldUrlsSelect = $this->connection->select();
        $oldUrlsSelect->from(
            $this->resource->getTableName(self::TABLE_NAME)
        );
        /** @var UrlRewrite $url */
        foreach ($urls as $url) {
            $oldUrlsSelect->orWhere(
                $this->connection->quoteIdentifier(
                    UrlRewrite::ENTITY_TYPE
                ) . ' = ?',
                $url->getEntityType()
            );
            $oldUrlsSelect->where(
                $this->connection->quoteIdentifier(
                    UrlRewrite::ENTITY_ID
                ) . ' = ?',
                $url->getEntityId()
            );
            $oldUrlsSelect->where(
                $this->connection->quoteIdentifier(
                    UrlRewrite::STORE_ID
                ) . ' = ?',
                $url->getStoreId()
            );
        }

        // prevent query locking in a case when nothing to delete
        $checkOldUrlsSelect = clone $oldUrlsSelect;
        $checkOldUrlsSelect->reset(Select::COLUMNS);
        $checkOldUrlsSelect->columns('count(*)');
        $hasOldUrls = (bool)$this->connection->fetchOne($checkOldUrlsSelect);

        if ($hasOldUrls) {
            $this->connection->query(
                $oldUrlsSelect->deleteFromSelect(
                    $this->resource->getTableName(self::TABLE_NAME)
                )
            );
        }
    }
    protected function doReplace(array $urls)
    {
        try {
            $this->deleteOldUrls($urls);
            $tableName = $this->resource->getTableName(self::TABLE_NAME);
            $data = [];
            $storeId_requestPaths = [];
            foreach ($urls as $url) {
                $storeId = $url->getStoreId();
                $requestPath = $url->getRequestPath();
                if ($requestPath){
                    $url->setRequestPath($requestPath);
                }
                // Skip if is exist in the database
                $exists = $this->connection->fetchOne(
                    "SELECT * FROM $tableName where store_id = :store_id and request_path = :request_path",
                    [
                        ":store_id" => $storeId,
                        ":request_path" => $requestPath
                    ]
                );

                if ($exists) continue;

                $storeId_requestPaths[] = $storeId . '-' . $requestPath;
                $data[] = $url->toArray();
            }
            // Remove duplication url
            $n = count($storeId_requestPaths);
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if ($storeId_requestPaths[$i] == $storeId_requestPaths[$j]) {
                        unset($data[$j]);
                    }
                }
            }

            //remove links old
            foreach( $data as $key => $info ){
                if (isset($info['target_path']) && ( stristr($info['target_path'],'/category/1') || stristr($info['target_path'],'/category/2') ) && $info['entity_type']=='product' || $info['request_path'] == "" ){
                    unset($data[$key]);
                }
            }

            // create links
            if( count($data) > 0 ){
//                file_put_contents('var/log/url_rewite.log', print_r($data, TRUE), FILE_APPEND);
                $this->insertMultiple($data);
            }
        } catch (\Exception $e) {
            // Nothing
        }

        return $urls;
    }
}
