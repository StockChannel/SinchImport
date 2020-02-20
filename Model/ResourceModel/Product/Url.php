<?php

namespace SITC\Sinchimport\Model\ResourceModel\Product;

class Url extends \Magento\Catalog\Model\ResourceModel\Url
{
    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;
    
    /**
     * Catalog product
     *
     * @var \Magento\Catalog\Model\Product
     */
    protected $_catalogProduct;
    
    /**
     * @var \Magento\Framework\Filter\FilterManager
     */
    protected $filter;
    
    /**
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Store\Model\StoreManagerInterface        $storeManager
     * @param \Magento\Eav\Model\Config                         $eavConfig
     * @param \Magento\Catalog\Model\ResourceModel\Product      $productResource
     * @param \Magento\Catalog\Model\Category                   $catalogCategory
     * @param \Psr\Log\LoggerInterface                          $logger
     * @param string                                            $connectionName
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Catalog\Model\ResourceModel\Product $productResource,
        \Magento\Catalog\Model\Category $catalogCategory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Catalog\Model\ProductFactory $catalogProduct,
        \Magento\Framework\Filter\FilterManager $filter,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        $connectionName = null
    ){
        parent::__construct(
            $context,
            $storeManager,
            $eavConfig,
            $productResource,
            $catalogCategory,
            $logger,
            $connectionName
        );
        $this->_catalogProduct = $catalogProduct;
        $this->filter = $filter;
        $this->scopeConfig = $scopeConfig;
    }
    
    /**
     * Retrieve Product data objects
     *
     * @param int|array $productIds
     * @param int       $storeId
     * @param int       $entityId
     * @param int       &$lastEntityId
     *
     * @return array
     */
    protected function _getProducts(
        $productIds,
        $storeId,
        $entityId,
        &$lastEntityId
    ) {
        $products   = [];
        $websiteId  = $this->_storeManager->getStore($storeId)->getWebsiteId();
        $connection = $this->getConnection();
        if ($productIds !== null) {
            if (! is_array($productIds)) {
                $productIds = [$productIds];
            }
        }
        $bind   = ['website_id' => (int)$websiteId,
                   'entity_id'  => (int)$entityId];
        $select = $connection->select()->useStraightJoin(
            true
        )->from(
            ['e' => $this->getTable('catalog_product_entity')],
            ['entity_id', 'sku']
        )->join(
            ['w' => $this->getTable('catalog_product_website')],
            'e.entity_id = w.product_id AND w.website_id = :website_id',
            []
        )->where(
            'e.entity_id > :entity_id'
        )->order(
            'e.entity_id'
        )->limit(
            $this->_productLimit
        );
        if ($productIds !== null) {
            $select->where('e.entity_id IN(?)', $productIds);
        }
        
        $rowSet = $connection->fetchAll($select, $bind);
        foreach ($rowSet as $row) {
            $product = $this->_catalogProduct->create();
            $product->setId($row['entity_id']);
            $product->setEntityId($row['entity_id']);
            $product->setSku($row['sku']);
            $product->setCategoryIds([]);
            $product->setStoreId($storeId);
            $products[$product->getId()] = $product;
            $lastEntityId = $product->getId();
        }
        
        unset($rowSet);
        
        if ($products) {
            $select     = $connection->select()->from(
                $this->getTable('catalog_category_product'),
                ['product_id', 'category_id']
            )->where(
                'product_id IN(?)',
                array_keys($products)
            );
            $categories = $connection->fetchAll($select);
            foreach ($categories as $category) {
                $productId     = $category['product_id'];
                $categoryIds   = $products[$productId]->getCategoryIds();
                $categoryIds[] = $category['category_id'];
                $products[$productId]->setCategoryIds($categoryIds);
            }
            
            foreach (['name', 'url_key', 'url_path'] as $attributeCode) {
                $attributes = $this->_getProductAttribute(
                    $attributeCode,
                    array_keys($products),
                    $storeId
                );
                foreach ($attributes as $productId => $attributeValue) {
                    if ($attributeCode == 'url_key' && empty($attributeValue)) {
                        $products[$productId]->setData(
                            $attributeCode,
                            $this->formatUrlKey($products[$productId])
                        );
                        $this->saveProductAttribute(
                            $products[$productId],
                            $attributeCode
                        );
                    } else {
                        $products[$productId]->setData(
                            $attributeCode,
                            $attributeValue
                        );
                    }
                }
            }
        }
        
        return $products;
    }
    
    /**
     * Format Key for URL
     *
     * @param \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    public function formatUrlKey(\Magento\Catalog\Model\Product $product)
    {
        $productNameTemplate = \strtolower($this->scopeConfig->getValue(
            'sinchimport/seo/product_name_template',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        ));

        $validReplacements = [
            '{name}' => $product->getName(),
            '{sku}' => $product->getSku(),
            '{id}' => $product->getId(),
            '{ean}' => $this->getProductAttributeText($product->getId(), 'ean'),
            '{unspsc}' => $this->getProductAttributeText($product->getId(), 'unspsc'),
            '{brand}' => $this->getProductAttributeText($product->getId(), 'manufacturer'),
            '{' => '', //These last 2 just ensure that no spare braces are left in the output
            '}' => ''
        ];

        foreach($validReplacements as $k => $v) {
            $productNameTemplate = str_replace($k, $v, $productNameTemplate);
        }
        
        return $this->filter->translitUrl(
            $productNameTemplate
        );
    }
    
    /**
     * Save product attribute
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string                         $attributeCode
     *
     * @return \SITC\Sinchimport\Model\ResourceModel\Product\Url
     */
    public function saveProductAttribute(
        \Magento\Catalog\Model\Product $product,
        $attributeCode
    ) {
        $connection = $this->getConnection();
        if (! isset($this->_productAttributes[$attributeCode])) {
            $attribute = $this->getProductModel()->getResource()->getAttribute(
                $attributeCode
            );
            
            $this->_productAttributes[$attributeCode] = [
                'attribute_id' => $attribute->getId(),
                'table'        => $attribute->getBackend()->getTable(),
                'is_global'    => $attribute->getIsGlobal()
            ];
            unset($attribute);
        }
        
        $attributeTable = $this->_productAttributes[$attributeCode]['table'];
        
        $attributeData = [
            'attribute_id' => $this->_productAttributes[$attributeCode]['attribute_id'],
            'store_id'     => $product->getStoreId(),
            'entity_id'    => $product->getId(),
            'value'        => $product->getData($attributeCode)
        ];
        
        if ($this->_productAttributes[$attributeCode]['is_global']
            || $product->getStoreId() == 0
        ) {
            $attributeData['store_id'] = 0;
        }
        
        $select = $connection->select()
            ->from($attributeTable)
            ->where('attribute_id = ?', (int)$attributeData['attribute_id'])
            ->where('store_id = ?', (int)$attributeData['store_id'])
            ->where('entity_id = ?', (int)$attributeData['entity_id']);
        
        $row = $connection->fetchRow($select);
        if ($row) {
            $whereCond = ['value_id = ?' => $row['value_id']];
            $connection->update($attributeTable, $attributeData, $whereCond);
        } else {
            $connection->insert($attributeTable, $attributeData);
        }
        
        if ($attributeData['store_id'] != 0) {
            $attributeData['store_id'] = 0;
            $select                    = $connection->select()
                ->from($attributeTable)
                ->where('attribute_id = ?', (int)$attributeData['attribute_id'])
                ->where('store_id = ?', (int)$attributeData['store_id'])
                ->where('entity_id = ?', (int)$attributeData['entity_id']);
            
            $row = $connection->fetchRow($select);
            if ($row) {
                $whereCond = ['value_id = ?' => $row['value_id']];
                $connection->update(
                    $attributeTable,
                    $attributeData,
                    $whereCond
                );
            } else {
                $connection->insert($attributeTable, $attributeData);
            }
        }
        unset($attributeData);
        
        return $this;
    }


    private function getProductAttributeText($productId, $attribute_code)
    {
        $eavAttrTable = $this->getTable('eav_attribute');
        $conn = $this->getConnection();
        $attr = $conn->fetchRow(
            "SELECT attribute_id, backend_type, frontend_input FROM {$eavAttrTable} WHERE entity_type_id = 4 AND attribute_code = :attrCode",
            [':attrCode' => $attribute_code]
        );

        $valueTable = $this->getTable("catalog_product_entity_{$attr['backend_type']}");
        $value = $conn->fetchRow(
            "SELECT value, store_id FROM {$valueTable} WHERE attribute_id = :attrId AND entity_id = :prodId",
            [
                ':attrId' => $attr['attribute_id'],
                ':prodId' => $productId
            ]
        );

        if($attr['frontend_input'] == 'select') { //Attribute is actually eav_attribute_option_value
            $eaovTable = $this->getTable('eav_attribute_option_value');
            return $conn->fetchOne(
                "SELECT value FROM {$eaovTable} WHERE option_id = :optId AND store_id = :store",
                [
                    ':optId' => $value['value'],
                    ':store' => $value['store_id']
                ]
            );
        }

        return $value['value'];
    }
}
