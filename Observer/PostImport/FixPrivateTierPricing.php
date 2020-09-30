<?php
namespace SITC\Sinchimport\Observer\PostImport;

/**
 * Fixes tier pricing for private products (ones with their base price set to 0 and tier price(s) > 0)
 */
class FixPrivateTierPricing implements \Magento\Framework\Event\ObserverInterface
{
    private $resourceConn;
    private $logger;
    private $helper;

    private $cpeTable;
    private $cpeDecimalTable;
    private $tierPriceTable;
    /**
     * Holds the attribute_id for Product Price
     * @var int
     */
    private $prodPriceAttr;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        $this->resourceConn = $resourceConn;
        $this->logger = $logger->withName("FixPrivateTierPricing");
        $this->helper = $helper;

        $this->cpeTable = $this->resourceConn->getTableName('catalog_product_entity');
        $this->cpeDecimalTable = $this->resourceConn->getTableName('catalog_product_entity_decimal');
        $this->tierPriceTable = $this->resourceConn->getTableName('catalog_product_entity_tier_price');

        $eavAttr = $this->resourceConn->getTableName('eav_attribute');
        $this->prodPriceAttr = $this->getConn()->fetchOne(
            "SELECT attribute_id FROM {$eavAttr} WHERE attribute_code = :code AND entity_type_id = :entityType",
            [':code' => 'price', ':entityType' => 4]
        );
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $this->logger->info("Fixing tier pricing for private products");

        //Establish how much to mark up the ceiling (multiplier)
        $adjustRatio = 1.0;
        $markupPercent = $this->helper->getStoreConfig('sinchimport/group_pricing/private_product_markup_pct');
        if(is_numeric($markupPercent)) {
            $markupNum = (int)$markupPercent;
            if($markupNum < 0 && $markupNum > 1000) {
                $this->logger->info("Warning: private_product_markup_pct configured to {$markupNum}, using 0 instead");
            } else {
                $adjustRatio = $adjustRatio + ($markupNum * 0.01);
            }
        }
        $this->logger->info("Adjust ratio for tier pricing fix is {$adjustRatio}");

        //TODO: This may need to check whether the products are sinch products (with a join on catalog_product_entity or sinch_products_mapping)
        $changes = $this->getConn()->fetchAll(
            "SELECT tier.entity_id, MAX(tier.value) * :adjustRatio AS adjusted_ceil, COUNT(tier.value) AS val_count FROM {$this->tierPriceTable} tier
                INNER JOIN {$this->cpeDecimalTable} price
                    ON tier.entity_id = price.entity_id
                    AND price.attribute_id = :priceAttr
                    AND price.store_id = 0
                WHERE tier.qty = 1
                    AND tier.all_groups = 0
                    AND tier.website_id = 0
                    AND price.value = 0
                GROUP BY tier.entity_id
                HAVING MAX(tier.value) > 0",
            [
                ':priceAttr' => $this->prodPriceAttr,
                ':adjustRatio' => $adjustRatio
            ]
        );
        $this->logger->info(count($changes) . " products are candidates for price modifications to correct tier pricing");

        foreach($changes as $change) {
            $this->logger->info("Product {$change['entity_id']} has {$change['val_count']} group prices, with an adjusted ceiling of {$change['adjusted_ceil']}");
            //Now adjust the base price (in catalog_product_entity_decimal) to be the adjusted ceiling, so tier prices apply properly
            $this->getConn()->query(
               "UPDATE {$this->cpeDecimalTable} SET value = :adjustedCeil
                    WHERE attribute_id = :priceAttr
                    AND entity_id = :entityId
                    AND store_id = 0",
                [
                    ':priceAttr' => $this->prodPriceAttr,
                    ':entityId' => $change['entity_id'],
                    ':adjustedCeil' => $change['adjusted_ceil']
                ]
            );
        }

        $this->logger->info("Finished fixing tier pricing for private products");
    }

    private function getConn()
    {
        return $this->resourceConn->getConnection();
    }
}