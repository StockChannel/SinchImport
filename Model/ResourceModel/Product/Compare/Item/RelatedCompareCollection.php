<?php

namespace SITC\Sinchimport\Model\ResourceModel\Product\Compare\Item;

class RelatedCompareCollection extends \Magento\Catalog\Model\ResourceModel\Product\Compare\Item\Collection {

    public function setIncludedIds(int $primary, array $includedIds): static
    {
        $this->addFieldToFilter('entity_id', ['in' => array_merge([$primary], $includedIds)]);

        $cases = "CASE WHEN e.entity_id = {$primary} THEN 0 ";
        for ($i = 0; $i < count($includedIds); $i++) {
            $order = $i + 1;
            $cases .= "WHEN e.entity_id = {$includedIds[$i]} THEN {$order} ";
        }
        $cases .= "END";
        $this->getSelect()->order(new \Zend_Db_Expr($cases));
        return $this;
    }

    // Identical to the parent implementation except we remove references to the compare table
    protected function _getAttributeSetIds(): array
    {
        // prepare website filter
        $websiteId = (int)$this->_storeManager->getStore($this->getStoreId())->getWebsiteId();
        $websiteConds = [
            'website.product_id = entity.entity_id',
            $this->getConnection()->quoteInto('website.website_id = ?', $websiteId),
        ];

        // retrieve attribute sets
        $select = $this->getConnection()->select()->distinct(
            true
        )->from(
            ['entity' => $this->getEntity()->getEntityTable()],
            'attribute_set_id'
        )->join(
            ['website' => $this->getTable('catalog_product_website')],
            join(' AND ', $websiteConds),
            []
        );
        return $this->getConnection()->fetchCol($select);
    }
}