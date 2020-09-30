<?php
namespace SITC\Sinchimport\Observer\PostImport;

/**
 * Fixes quote items for products which have changed ID
 */
class FixQuoteItems implements \Magento\Framework\Event\ObserverInterface
{
    private $resourceConn;
    private $logger;
    private $helper;

    private $cpeTable;
    private $cpevTable;
    private $quoteItemTable;
    /**
     * Holds the attribute_id for Product Name
     * @var int
     */
    private $prodNameAttr;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        $this->resourceConn = $resourceConn;
        $this->logger = $logger->withName("FixQuoteItems");
        $this->helper = $helper;
        $this->cpeTable = $this->resourceConn->getTableName('catalog_product_entity');
        $this->cpevTable = $this->resourceConn->getTableName('catalog_product_entity_varchar');
        $this->quoteItemTable = $this->resourceConn->getTableName('quote_item');
        $eavAttr = $this->resourceConn->getTableName('eav_attribute');
        $this->prodNameAttr = $this->getConn()->fetchOne(
            "SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = :code AND entity_type_id = :entityType",
            [':code' => 'name', ':entityType' => 4]
        );
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->logger->info("Fixing quote items");
        $affected_items = $this->getConn()->fetchAll(
            "SELECT qi.item_id, qi.name, cpe_sku.entity_id, qi.store_id FROM {$this->quoteItemTable} qi
                LEFT JOIN {$this->cpeTable} cpe_pid ON qi.product_id = cpe_pid.entity_id
                LEFT JOIN {$this->cpeTable} cpe_sku ON qi.sku = cpe_sku.sku
                WHERE cpe_pid.entity_id IS NULL
                AND cpe_sku.entity_id IS NOT NULL
                AND cpe_sku.sinch_product_id IS NOT NULL" //Only consider sinch products to be candidates
        );
        /* If you need to find out which products were changed where they would have been rejected by $verifyName
            you can run this SQL (this returns all products which have changed name, not just ones that this observer would touch):
            SELECT DISTINCT qi.name as previous_name, qi.sku, qi.product_id, cpev.value as current_name
                FROM quote_item qi
                INNER JOIN catalog_product_entity_varchar cpev
                    ON qi.product_id = cpev.entity_id
                    AND cpev.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'name')
                WHERE qi.name != cpev.value;
        */

        $updatedItems = 0;
        $update = $this->getConn()->prepare("UPDATE {$this->quoteItemTable} SET product_id = :prodId WHERE item_id = :itemId");
        $verifyName = $this->helper->getStoreConfig('sinchimport/misc/quotes_fix_verify_name');

        foreach($affected_items as $row) {
            //Check old name and candidate name match
            $candidateName = $this->getProductName($row['entity_id'], $row['store_id']);
            if($verifyName && $row['name'] != $candidateName) {
                $this->logger->warn("Found candidate product for quote_item {$row['item_id']}, but candidate name doesn't match, skipping: {$row['name']} != {$candidateName}");
                continue;
            }
            $update->bindValue(":itemId", $row['item_id'], \PDO::PARAM_INT);
            $update->bindValue(":prodId", $row['entity_id'], \PDO::PARAM_INT);
            $update->execute();
            $update->closeCursor();
            $updatedItems++;
        }

        if($updatedItems > 0) {
            $this->logger->info("Updated {$updatedItems} quote_items with new product mappings");
        }
    }

    private function getConn()
    {
        return $this->resourceConn->getConnection();
    }

    /**
     * Gets the product name for the given product and store id
     * @param int $productId
     * @param int $storeId
     * @return string
     */
    private function getProductName($productId, $storeId)
    {
        return $this->getConn()->fetchOne(
            "SELECT value FROM {$this->cpevTable} WHERE attribute_id = :attrId AND entity_id = :prodId AND store_id = :storeId",
            [
                ':attrId' => $this->prodNameAttr,
                ':prodId' => $productId,
                ':storeId' => $storeId
            ]
        );
    }
}