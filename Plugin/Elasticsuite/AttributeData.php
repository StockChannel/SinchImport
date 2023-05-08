<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Store\Model\ScopeInterface;
use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Api\Index\DatasourceInterface;
use Smile\ElasticsuiteCore\Api\Index\Mapping\DynamicFieldProviderInterface;
use Smile\ElasticsuiteCatalog\Model\ResourceModel\Eav\Indexer\Fulltext\Datasource\AbstractAttributeData as ResourceModel;
use Smile\ElasticsuiteCore\Index\Mapping\FieldFactory;
use Smile\ElasticsuiteCatalog\Helper\AbstractAttribute as AttributeHelper;

/**
 * This class overrides AttributeData within ElasticsuiteCatalog for the purpose of merging sinch_restrict attributes on composite/grouped/bundle products
 */
class AttributeData extends \Smile\ElasticsuiteCatalog\Model\Product\Indexer\Fulltext\Datasource\AttributeData implements DatasourceInterface, DynamicFieldProviderInterface
{
    /** @var string */
    protected const XML_PATH_INDEX_CHILD_PRODUCT_SKU = 'smile_elasticsuite_catalogsearch_settings/catalogsearch/index_child_product_sku';

    protected ?ScopeConfigInterface $scopeConfig = null;
    protected array $forbiddenChildrenAttributes = [];
    protected bool $isIndexingChildProductSkuEnabled;

    private Logger $logger;

    public function __construct(
        ResourceModel $resourceModel,
        FieldFactory $fieldFactory,
        AttributeHelper $attributeHelper,
        array $indexedBackendModels = [],
        array $forbiddenChildrenAttributes = [],
        ScopeConfigInterface $scopeConfig = null
    ) {
        parent::__construct($resourceModel, $fieldFactory, $attributeHelper, $indexedBackendModels, $forbiddenChildrenAttributes, $scopeConfig);

        $writer = new Stream(BP . '/var/log/sinch_custom_catalog.log');
        $this->logger = new Logger();
        $this->logger->addWriter($writer);
    }

    /*
     * Should exactly match default implementation.
     * Unfortunately said implementation marks every other function in this class private,
     * and as such, we are forced to redeclare to ensure our versions are used
     */
    /**
     * {@inheritdoc}
     */
    public function addData($storeId, array $indexData)
    {
        $productIds   = array_keys($indexData);
        $indexData    = $this->addAttributeData($storeId, $productIds, $indexData);

        $relationsByChildId = $this->resourceModel->loadChildrens($productIds, $storeId);

        if (!empty($relationsByChildId)) {
            $allChildrenIds      = array_keys($relationsByChildId);
            $childrenIndexData   = $this->addAttributeData($storeId, $allChildrenIds);

            foreach ($childrenIndexData as $childrenId => $childrenData) {
                $enabled = isset($childrenData['status']) && current($childrenData['status']) == 1;
                if ($enabled === false) {
                    unset($childrenIndexData[$childrenId]);
                }
            }

            foreach ($relationsByChildId as $childId => $relations) {
                foreach ($relations as $relation) {
                    $parentId = (int) $relation['parent_id'];
                    if (isset($indexData[$parentId]) && isset($childrenIndexData[$childId])) {
                        $indexData[$parentId]['children_ids'][] = $childId;
                        $this->addRelationData($indexData[$parentId], $childrenIndexData[$childId], $relation);
                        $this->addChildData($indexData[$parentId], $childrenIndexData[$childId]);
                        $this->addChildSku($indexData[$parentId], $relation);
                    }
                }
            }
        }

        return $this->filterCompositeProducts($indexData);
    }

    /**
     * Append attribute data to the index.
     *
     * @param int   $storeId    Indexed store id.
     * @param array $productIds Indexed product ids.
     * @param array $indexData  Original indexed data.
     *
     * @return array
     */
    protected function addAttributeData($storeId, $productIds, $indexData = [])
    {
        foreach ($this->attributeIdsByTable as $backendTable => $attributeIds) {
            $attributesData = $this->loadAttributesRawData($storeId, $productIds, $backendTable, $attributeIds);
            foreach ($attributesData as $row) {
                $productId   = (int) $row['entity_id'];
                $indexValues = $this->attributeHelper->prepareIndexValue($row['attribute_id'], $storeId, $row['value']);
                if (!isset($indexData[$productId])) {
                    $indexData[$productId] = [];
                }

                $indexData[$productId] += $indexValues;

                $this->addIndexedAttribute($indexData[$productId], $row['attribute_code']);
            }
        }

        return $indexData;
    }

    // Differs from the default implementation in that it merges sinch_restrict values into a single rule
    /**
     * Append data of child products to the parent.
     *
     * @param array $parentData      Parent product data.
     * @param array $childAttributes Child product attributes data.
     *
     * @return void
     */
    protected function addChildData(&$parentData, $childAttributes)
    {
        $authorizedChildAttributes = $parentData['children_attributes'];
        $addedChildAttributesData  = array_filter(
            $childAttributes,
            function ($attributeCode) use ($authorizedChildAttributes) {
                return in_array($attributeCode, $authorizedChildAttributes);
            },
            ARRAY_FILTER_USE_KEY
        );

        foreach ($addedChildAttributesData as $attributeCode => $value) {
            if (!isset($parentData[$attributeCode])) {
                $parentData[$attributeCode] = [];
            }

            if ($attributeCode === "sinch_restrict") {
                $before = $parentData[$attributeCode];
                // Special logic to merge the rules into 1
                $parentData[$attributeCode] = Data::mergeCCRules($parentData[$attributeCode], $value);
                if (count($before) == 1 && count($value) == 1) {
                    $this->logger->info("Sinch_restrict merge: {$before[0]} + {$value[0]} = {$parentData[$attributeCode][0]}");
                }
                continue;
            }

            $parentData[$attributeCode] = array_values(array_unique(array_merge($parentData[$attributeCode], $value)));
        }
    }

    /**
     * Append relation information to the index for composite products.
     *
     * @param array $parentData      Parent product data.
     * @param array $childAttributes Child product attributes data.
     * @param array $relation        Relation data between the child and the parent.
     *
     * @return void
     */
    protected function addRelationData(&$parentData, $childAttributes, $relation)
    {
        $childAttributeCodes  = array_keys($childAttributes);

        if (!isset($parentData['children_attributes'])) {
            $parentData['children_attributes'] = ['indexed_attributes'];
        }

        $childrenAttributes = array_merge(
            $parentData['children_attributes'],
            array_diff($childAttributeCodes, $this->forbiddenChildrenAttributes)
        );

        if (isset($relation['configurable_attributes']) && !empty($relation['configurable_attributes'])) {
            $attributesCodes = array_map(
                function (int $attributeId) {
                    if (isset($this->attributesById[$attributeId])) {
                        return $this->attributesById[$attributeId]->getAttributeCode();
                    }
                },
                $relation['configurable_attributes']
            );

            $parentData['configurable_attributes'] = array_values(
                array_unique(
                    array_merge($attributesCodes, $parentData['configurable_attributes'] ?? [])
                )
            );
        }

        $parentData['children_attributes'] = array_values(array_unique($childrenAttributes));
    }

    /**
     * Filter out composite product when no enabled children are attached.
     *
     * @param array $indexData Indexed data.
     *
     * @return array
     */
    protected function filterCompositeProducts($indexData)
    {
        $compositeProductTypes = $this->resourceModel->getCompositeTypes();

        foreach ($indexData as $productId => $productData) {
            $isComposite = in_array($productData['type_id'], $compositeProductTypes);
            $hasChildren = isset($productData['children_ids']) && !empty($productData['children_ids']);
            if ($isComposite && !$hasChildren) {
                unset($indexData[$productId]);
            }
        }

        return $indexData;
    }

    /**
     * Append SKU of children product to the parent product index data.
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     *
     * @param array $parentData Parent product data.
     * @param array $relation   Relation data between the child and the parent.
     */
    protected function addChildSku(&$parentData, $relation)
    {
        if (isset($parentData['sku']) && !is_array($parentData['sku'])) {
            $parentData['sku'] = [$parentData['sku']];
        }

        if (!$this->isIndexChildProductSkuEnabled()) {
            $parentData['sku'][] = $relation['sku'];
            $parentData['sku'] = array_unique($parentData['sku']);
        } else {
            $parentData['children_skus'][] = $relation['sku'];
            $parentData['children_skus'] = array_unique($parentData['children_skus']);
        }
    }

    /**
     * Append an indexed attributes to indexed data of a given product.
     *
     * @param array  $productIndexData Product Index data
     * @param string $attributeCode    The attribute code
     */
    protected function addIndexedAttribute(&$productIndexData, $attributeCode)
    {
        if (!isset($productIndexData['indexed_attributes'])) {
            $productIndexData['indexed_attributes'] = [];
        }

        // Data can be missing for this attribute (Eg : due to null value being escaped,
        // or this attribute is already included in the array).
        if (isset($productIndexData[$attributeCode])
            && !in_array($attributeCode, $productIndexData['indexed_attributes'])
        ) {
            $productIndexData['indexed_attributes'][] = $attributeCode;
        }
    }

    /**
     * Is indexing child product SKU in dedicated subfield enabled?
     *
     * @return bool
     */
    protected function isIndexChildProductSkuEnabled(): bool
    {
        if (!isset($this->isIndexingChildProductSkuEnabled)) {
            $this->isIndexingChildProductSkuEnabled = (bool) $this->getScopeConfig()->getValue(
                self::XML_PATH_INDEX_CHILD_PRODUCT_SKU,
                ScopeInterface::SCOPE_STORE
            );
        }

        return $this->isIndexingChildProductSkuEnabled;
    }

    /**
     * Get Scope Config object. It can be null to allow BC.
     *
     * @return ScopeConfigInterface
     */
    protected function getScopeConfig() : ScopeConfigInterface
    {
        if (null === $this->scopeConfig) {
            $this->scopeConfig = ObjectManager::getInstance()->get(ScopeConfigInterface::class);
        }

        return $this->scopeConfig;
    }
}