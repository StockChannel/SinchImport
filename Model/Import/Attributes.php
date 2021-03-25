<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;

class Attributes extends AbstractImportSection {
    const LOG_PREFIX = "Attributes: ";
    const LOG_FILENAME = "attributes";

    const ATTRIBUTE_GROUP_NAME = "Sinch features";
    const ATTRIBUTE_GROUP_SORT = 50;
    const ATTRIBUTE_PREFIX = "sinch_attr_";

    const PRODUCT_PAGE_SIZE = 50;

    //Stats
    private $attributeCount = 0;
    private $attributesCreated = 0;
    private $attributesUpdated = 0;
    private $attributesDeleted = 0;
    private $optionCount = 0;
    private $optionsCreated = 0;
    private $optionsUpdated = 0;
    private $optionsDeleted = 0;

    //CSV parser
    private $csv;

    //Attributes to produce
    private $attributes = [];
    //Sinch RV -> [Prod]
    private $rvProds = [];

    private $attributeRepository;
    private $attributeGroupRepository;
    private $attributeFactory;
    private $attributeGroupFactory;
    private $searchCriteriaBuilder;
    private $optionManagement;
    private $optionFactory;
    private $attributeSetRepository;
    private $attributeManagement;

    private $cacheType;
    private $massProdValues;
    private $scopeConfig;

    private $attributeSetCache = null;
    private $attributeGroupIds = [];

    private $mappingTable;
    private $cpeTable;
    private $filterCategoriesTable;
    private $eavAttrTable;
    private $eavOptionValueTable;

    private $mappingInsert = null;
    private $mappingQuery = null;
    private $filterMappingInsert = null;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \Magento\Framework\File\Csv $csv,
        \Magento\Catalog\Api\ProductAttributeRepositoryInterface $attributeRepository,
        \Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface $attributeGroupRepository,
        \Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory $attributeFactory,
        \Magento\Eav\Api\Data\AttributeGroupInterfaceFactory $attributeGroupFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Catalog\Api\ProductAttributeOptionManagementInterface $optionManagement,
        \Magento\Eav\Api\Data\AttributeOptionInterfaceFactory $optionFactory,
        \Magento\Catalog\Api\AttributeSetRepositoryInterface $attributeSetRepository,
        \Magento\Catalog\Api\ProductAttributeManagementInterface $attributeManagement,
        \Magento\Framework\App\Cache\TypeListInterface $cacheType,
        \Magento\Catalog\Model\ResourceModel\Product\Action $massProdValues,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        parent::__construct($resourceConn, $output);
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        $this->attributeRepository = $attributeRepository;
        $this->attributeGroupRepository = $attributeGroupRepository;
        $this->attributeFactory = $attributeFactory;
        $this->attributeGroupFactory = $attributeGroupFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->optionManagement = $optionManagement;
        $this->optionFactory = $optionFactory;
        $this->attributeSetRepository = $attributeSetRepository;
        $this->attributeManagement = $attributeManagement;
        $this->cacheType = $cacheType;
        $this->massProdValues = $massProdValues;
        $this->scopeConfig = $scopeConfig;

        $this->mappingTable = $this->getTableName('sinch_restrictedvalue_mapping');
        $this->cpeTable = $this->getTableName('catalog_product_entity');
        $this->filterCategoriesTable = $this->getTableName('sinch_filter_categories');
        $this->eavAttrTable = $this->getTableName("eav_attribute");
        $this->eavOptionValueTable = $this->getTableName("eav_attribute_option_value");
    }

    /**
     * @param string $categoryFeaturesFile CategoryFeatures.csv
     * @param string $restrictedValuesFile RestrictedValues.csv
     * @param string $productFeaturesFile ProductFeatures.csv
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     * @throws \Exception
     */
    public function parse(string $categoryFeaturesFile, string $restrictedValuesFile, string $productFeaturesFile)
    {
        $this->log("--- Begin Attribute Parse ---");

        $this->startTimingStep("Parse raw files");
        //ID, CategoryID, Name, Order
        $category_features = $this->csv->getData($categoryFeaturesFile);
        unset($category_features[0]); //Unset the first entry as the sinch export files have a header row
        //ID, CategoryFeatureID, Text, Order
        $attribute_values = $this->csv->getData($restrictedValuesFile);
        unset($attribute_values[0]);
        //ID, ProductID, RestrictedValueID
        $product_features = $this->csv->getData($productFeaturesFile);
        unset($product_features[0]);
        $this->endTimingStep();

        $this->createAttributeGroups();

        $this->startTimingStep("Parsing category features");
        $this->log("Parsing category features file");
        foreach($category_features as $feature_row){
            if(count($feature_row) != 4) {
                $this->logger->warn("Feature row not 4 columns");
                $this->logger->debug(print_r($feature_row, true));
                continue;
            }
            $this->attributes[$feature_row[0]] = [
                "catId" => $feature_row[1],
                "name" => $feature_row[2],
                "order" => $feature_row[3],
                "values" => []
            ];
        }
        $this->endTimingStep();

        $this->startTimingStep("Parse restricted values");
        $this->log("Parsing attribute values file");
        foreach($attribute_values as $rv_row){
            $this->attributes[$rv_row[1]]["values"][$rv_row[0]] = [
                "text" => $rv_row[2],
                "order" => $rv_row[3]
            ];
        }
        $this->endTimingStep();

        $this->startTimingStep("Parse product features");
        //RV -> [Product]
        foreach($product_features as $pf_row){
            $this->rvProds[$pf_row[2]][] = $pf_row[1];
        }
        $this->endTimingStep();

        $this->startTimingStep("Delete old filter-category mapping");
        //Delete old filter-category mapping (ok as it just tells the import which categories to display the filter on)
        $this->log("Deleting old filter-category mapping");
        $this->getConnection()->query("DELETE FROM {$this->filterCategoriesTable}");
        $this->endTimingStep();

        //Establish attribute names
        $attrNames = [];
        foreach (\array_keys($this->attributes) as $sinchAttrId) {
            $attrNames[] = self::ATTRIBUTE_PREFIX . $sinchAttrId;
        }


        $this->log("Figuring out which attributes have been removed");
        $this->startTimingStep("Removals");
        $replacement = \implode(",", \array_fill(0, \count($attrNames), '?'));
        $removedAttributes = [];
        if (!empty($replacement)) {
            $removedAttributes = $this->getConnection()->fetchCol(
                "SELECT attribute_code FROM {$this->eavAttrTable} WHERE attribute_code LIKE '" . self::ATTRIBUTE_PREFIX . "%' AND attribute_code NOT IN ({$replacement})",
                $attrNames
            );
        }
        if (count($removedAttributes) > 0) {
            $this->log("Removing " . count($removedAttributes) . " old filterable attributes");
            $replacement = \implode(",", \array_fill(0, \count($removedAttributes), '?'));
            $this->getConnection()->query("DELETE FROM {$this->eavAttrTable} WHERE attribute_code IN ({$replacement})", $removedAttributes);
            $this->attributesDeleted = count($removedAttributes);
        }
        $this->endTimingStep();

        $this->log("Figuring out which attributes need to be created");
        $this->startTimingStep("Creations");
        $existingAttributes = $this->getConnection()->fetchCol("SELECT attribute_code FROM {$this->eavAttrTable} WHERE attribute_code LIKE '". self::ATTRIBUTE_PREFIX ."%'");
        $missingAttributes = [];
        foreach ($attrNames as $attrName) {
            if (!\in_array($attrName, $existingAttributes)) {
                $missingAttributes[] = $attrName;
            }
        }

        $this->log("Creating missing attributes");
        foreach ($this->attributes as $sinch_id => $data) {
            if (!\in_array(self::ATTRIBUTE_PREFIX . $sinch_id, $missingAttributes)) {
                continue;
            }
            $this->createAttribute($sinch_id, $data);
            $this->attributesCreated += 1;
        }
        $this->endTimingStep();

        $this->startTimingStep("Update attribute values");
        foreach ($this->attributes as $sinch_id => $data) {
            $this->attributeCount += 1;
            $this->updateAttributeOptions($sinch_id, $data);
            $this->updateFilterCategoryMapping($sinch_id, $data["catId"]);
            $this->attributesUpdated += 1;
        }
        $this->endTimingStep();

        $this->startTimingStep("Flush EAV cache");
        $this->cacheType->cleanType('eav');
        $this->endTimingStep();

        $this->log("--- Completed Attribute parse ---");
        $this->log("Processed {$this->attributeCount} attributes ({$this->attributesCreated} created, {$this->attributesUpdated} updated, {$this->attributesDeleted} deleted).");
        $this->log("Processed {$this->optionCount} options ({$this->optionsCreated} created, {$this->optionsUpdated} updated, {$this->optionsDeleted} deleted).");
        $this->timingPrint();
    }

    /**
     * @param $sinch_id
     * @param $data
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     */
    private function createAttribute($sinch_id, $data)
    {
        $this->logger->info("Creating attribute " . self::ATTRIBUTE_PREFIX . $sinch_id);
        $attribute = $this->attributeFactory->create()
            ->setEntityTypeId(\Magento\Catalog\Model\Product::ENTITY)
            ->setAttributeCode(self::ATTRIBUTE_PREFIX . $sinch_id)
            ->setBackendType('int')
            ->setFrontendInput('select')
            ->setIsRequired(0)
            ->setIsUserDefined(1)
            ->setIsUnique(0);
        $attribute = $this->setAttributeConfig($attribute, $data);
        $this->attributeRepository->save($attribute);
        
        $this->logger->info("Assigning attribute " . self::ATTRIBUTE_PREFIX . $sinch_id . " to sets and groups");
        foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
            $this->attributeManagement->assign(
                $attributeSetId,
                $this->attributeGroupIds[$idx],
                self::ATTRIBUTE_PREFIX . $sinch_id,
                $data["order"]
            );
        }
    }

    //Sets the catalog_eav_attribute options
    private function setAttributeConfig($attribute, $data)
    {
        $attr_visible_in_admin = $this->scopeConfig->getValue(
            'sinchimport/attributes/visible_in_admin',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $attribute->setDefaultFrontendLabel($data["name"])
            ->setIsVisible($attr_visible_in_admin)
            ->setIsVisibleInGrid(0)
            ->setIsVisibleInAdvancedSearch(0)
            ->setIsVisibleOnFront(0)
            ->setIsUsedInGrid(0)
            ->setIsFilterable(1)
            ->setIsFilterableInGrid(0)
            ->setIsFilterableInSearch(0)
            ->setIsSearchable(0)
            ->setIsComparable(0)
            ->setUsedInProductListing(0)
            ->setPosition($data["order"]);
    }

    /**
     * @param $sinch_feature_id
     * @param $data
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     */
    private function updateAttributeOptions($sinch_feature_id, $data)
    {
        $attribute_code = self::ATTRIBUTE_PREFIX . $sinch_feature_id;
        $attribute = $this->attributeRepository->get(self::ATTRIBUTE_PREFIX . $sinch_feature_id);

        //Delete old options
        $items = $this->optionManagement->getItems($attribute_code);
        $this->logger->info("Deleting old options for attribute " . $attribute_code);
        foreach($items as $option){
            $id = $option->getValue();
            if($id == '') continue; //Magento seems to add an empty option to the array, ignore it

            //Check if it matches one of the ones we intend to add
            $label = $option->getLabel();
            $found = false;
            foreach($data["values"] as $sinch_value_id => $option_data){
                if($option_data["text"] == $label) $found = true;
            }
            if($found) {
                continue;
            }

            $this->optionManagement->delete($attribute_code, $id);
            $this->optionsDeleted += 1;
        }
        
        //Create new options
        $this->logger->info("Create new options (" . count($data["values"]) . ") for attribute " . $attribute_code);
        foreach($data["values"] as $sinch_value_id => $option_data){
            $this->optionCount += 1;

            $existing = $this->queryMapping($sinch_value_id);
            if (!empty($existing)) {
                $text = $this->getConnection()->fetchOne(
                    "SELECT value FROM {$this->eavOptionValueTable} WHERE option_id = :option_id",
                    [":option_id" => $existing['option_id']]
                );
                if (!empty($option_data['text']) && $text != $option_data['text']) {
                    //Update the value to match its new content
                    $this->getConnection()->query(
                        "UPDATE {$this->eavOptionValueTable} SET value = :val WHERE option_id = :option_id",
                        [
                            ":option_id" => $existing['option_id'],
                            ":val" => $option_data['text']
                        ]
                    );
                    $this->optionsUpdated += 1;
                }
                //Mapping exists, skip
                continue;
            }

            //Skip adding the option but still map it if it exists
            $existingOptId = $attribute->getSource()->getOptionId($option_data["text"]);
            if(is_numeric($existingOptId)){
                $this->addMapping($sinch_value_id, $sinch_feature_id, $existingOptId);
                $this->optionsUpdated += 1;
                continue;
            }

            $option = $this->optionFactory->create()
                ->setLabel($option_data["text"])
                ->setSortOrder($option_data["order"]);

            try {
                $this->optionManagement->add($attribute_code, $option);
                //Add succeeded
                $this->optionsCreated += 1;
                //Get option id
                $option_id = $attribute->getSource()->getOptionId($option_data["text"]);
                if(!is_numeric($option_id)){
                    foreach($this->optionManagement->getItems($attribute_code) as $option){
                        if($option->getLabel() == $option_data["text"]){
                            $this->addMapping($sinch_value_id, $sinch_feature_id, $option->getValue());
                        }
                    }
                } else {
                    //Insert a mapping record for the option
                    $this->addMapping($sinch_value_id, $sinch_feature_id, $option_id);
                }
            } catch (InputException $e) {
                $this->logger->warn("Failed to add option: " . $sinch_value_id . " - " . $option_data["text"] . "(InputException: " . $e->getMessage() . ")");
                //throw new \Magento\Framework\Exception\StateException(__("Failed to create option id: %1", $sinch_value_id));
            } catch (StateException $e) {
                $this->logger->warn("Failed to add option: " . $sinch_value_id . " - " . $option_data["text"] . "(StateException: " . $e->getMessage() . ")");
                //throw new \Magento\Framework\Exception\StateException(__("Failed to create option id: %1", $sinch_value_id));
            }
        }
    }

    /**
     * @throws NoSuchEntityException
     * @throws StateException
     */
    private function createAttributeGroups()
    {
        $criteria = $this->searchCriteriaBuilder->addFilter(\Magento\Eav\Api\Data\AttributeGroupInterface::GROUP_NAME, self::ATTRIBUTE_GROUP_NAME, "eq")->create();
        $groups = $this->attributeGroupRepository->getList($criteria)->getItems();
        $this->logger->info("Matching attribute groups: " . count($groups));

        if(count($groups) < 1){
            foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
                $this->logger->info("Creating attribute group for attribute set id: " . $attributeSetId);
                $ag = $this->attributeGroupFactory->create()
                    ->setAttributeGroupName(self::ATTRIBUTE_GROUP_NAME)
                    ->setAttributeSetId($attributeSetId)
                    ->setData('sort_order', self::ATTRIBUTE_GROUP_SORT);
                 $attributeGroup = $this->attributeGroupRepository->save($ag);
                 $this->attributeGroupIds[$idx] = $attributeGroup->getAttributeGroupId();
                 $this->logger->info("Attribute group for set id " . $attributeSetId . " is " . $this->attributeGroupIds[$idx]);
            }
        } else {
            $this->logger->info("Matching attribute groups to attribute sets");
            foreach($groups as $attributeGroup){
                $setId = $attributeGroup->getAttributeSetId();
                $matched = false;
                foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
                    if ($setId == $attributeSetId){
                        $matched = true;
                        $this->attributeGroupIds[$idx] = $attributeGroup->getAttributeGroupId();
                    }
                }
                if(!$matched){
                    $this->logger->err("Failed to match attribute group " . $attributeGroup->getAttributeGroupId() . " to an attribute set");
                }
            }
            if(count($groups) != count($this->attributeGroupIds)){
                //Missing a group
                //TODO: Add
                throw new StateException(__("An attribute group is missing"));
            }
        }
    }

    /**
     * @return array
     * @throws StateException
     */
    private function getAttributeSetIds()
    {
        if($this->attributeSetCache == null){
            $attributeSets = $this->attributeSetRepository->getList(
                $this->searchCriteriaBuilder->create()
            )->getItems();

            if(count($attributeSets) < 1){
                throw new StateException(__("Retrieved no attribute sets"));
            }

            $this->attributeSetCache = [];
            foreach($attributeSets as $attributeSet){
                $this->attributeSetCache[] = $attributeSet->getAttributeSetId();
            }
        }
        return $this->attributeSetCache;
    }

    private function addMapping($sinch_id, $sinch_feature_id, $option_id)
    {
        if(empty($this->mappingInsert)){
            $this->mappingInsert = $this->getConnection()->prepare(
                "REPLACE INTO {$this->mappingTable} (sinch_id, sinch_feature_id, option_id) VALUES(:sinch_id, :sinch_feature_id, :option_id)"
            );
        }

        $this->mappingInsert->bindValue(":sinch_id", $sinch_id, \PDO::PARAM_INT);
        $this->mappingInsert->bindValue(":sinch_feature_id", $sinch_feature_id, \PDO::PARAM_INT);
        $this->mappingInsert->bindValue(":option_id", $option_id, \PDO::PARAM_INT);
        $this->mappingInsert->execute();
        $this->mappingInsert->closeCursor();
    }

    private function queryMapping($rv_id)
    {
        if(empty($this->mappingQuery)){
            $this->mappingQuery = $this->getConnection()->prepare(
                "SELECT sinch_feature_id, option_id FROM {$this->mappingTable} WHERE sinch_id = :rv_id"
            );
        }

        $this->mappingQuery->bindValue(":rv_id", $rv_id, \PDO::PARAM_INT);
        $this->mappingQuery->execute();
        $result = $this->mappingQuery->fetch(\PDO::FETCH_ASSOC);
        $this->mappingQuery->closeCursor();
        return $result;
    }

    private function sinchToEntityIds($sinch_prod_ids)
    {
        $placeholders = implode(',', array_fill(0, count($sinch_prod_ids), '?'));
        $entIdQuery = $this->getConnection()->prepare(
            "SELECT entity_id FROM {$this->cpeTable} WHERE sinch_product_id IN ($placeholders)"
        );
        $entIdQuery->execute($sinch_prod_ids);
        return $entIdQuery->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    /**
     * @throws StateException
     * @throws \Exception
     */
    public function applyAttributeValues()
    {
        $applyStart = $this->microtime_float();
        $this->logger->info("--- Begin applying attribute values to products ---");

        $valueCount = count($this->rvProds);
        $currVal = 0;
        foreach($this->rvProds as $rv_id => $products){
            $currVal += 1;
            $attrData = $this->queryMapping($rv_id);
            if($attrData === false){
                $this->logger->err("Failed to retrieve attribute mapping for rv {$rv_id}");
                continue;
            }
            $entityIds = $this->sinchToEntityIds($products);
            if($entityIds === false){
                $this->logger->err("Failed to retrieve entity ids");
                throw new StateException(__("Failed to retrieve entity ids"));
            }
            $prodCount = count($entityIds);
            $this->logger->info("({$currVal}/{$valueCount}) Setting option id {$attrData['option_id']} for {$prodCount} products");
            $this->massProdValues->updateAttributes(
                $entityIds, 
                [self::ATTRIBUTE_PREFIX . $attrData['sinch_feature_id'] => $attrData['option_id']],
                0 //store id (dummy value as they're global attributes)
            );
        }

        $elapsed = number_format($this->microtime_float() - $applyStart, 2);
        $this->logger->info(
            "--- Completed applying attribute values. Took {$elapsed} seconds ---"
        );
    }

    private function updateFilterCategoryMapping($sinch_feature_id, $sinch_category_id)
    {
        if(empty($this->filterMappingInsert)){
            $this->filterMappingInsert = $this->getConnection()->prepare(
                "INSERT IGNORE INTO {$this->filterCategoriesTable} (feature_id, category_id) VALUES(:feature_id, :category_id)"
            );
        }

        $this->filterMappingInsert->bindValue(":feature_id", $sinch_feature_id, \PDO::PARAM_INT);
        $this->filterMappingInsert->bindValue(":category_id", $sinch_category_id, \PDO::PARAM_INT);
        $this->filterMappingInsert->execute();
        $this->filterMappingInsert->closeCursor();
    }
}