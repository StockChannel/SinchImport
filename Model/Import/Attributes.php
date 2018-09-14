<?php

namespace SITC\Sinchimport\Model\Import;

class Attributes {

    const ATTRIBUTE_GROUP_NAME = "Sinch features";
    const ATTRIBUTE_GROUP_SORT = 50;
    const ATTRIBUTE_PREFIX = "sinch_attr_";

    const PRODUCT_PAGE_SIZE = 50;

    //Stats
    private $attributeCount = 0;
    private $optionCount = 0;
    private $productsEdited = 0;
    private $valuesSet = 0;

    //CSV parser
    private $csv;

    //ID, CategoryID, Name, Order
    private $category_features;
    //ID, CategoryFeatureID, Text, Order
    private $attribute_values;
    //ID, ProductID, RestrictedValueID
    private $product_features;

    //Attributes to produce
    private $attributes;
    //Sinch product ID -> [RV] mapping
    private $productAttributeValues;

    private $attributeRepository;
    private $attributeGroupRepository;
    private $attributeFactory;
    private $attributeGroupFactory;
    private $searchCriteriaBuilder;
    private $optionManagement;
    private $optionFactory;
    private $attributeSetRepository;
    private $attributeManagement;

    //For setting the attributes on the products
    private $productCollectionFactory;
    //private $productRepository;
    private $productResource;
    private $resourceConn;

    private $attributeSetCache = null;
    private $attributeGroupIds = [];

    private $logger;

    private $mappingTable;
    private $mappingInsert = null;
    private $mappingQuery = null;

    public function __construct(
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
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        //\Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Catalog\Model\ResourceModel\Product $productResource,
        \Magento\Framework\App\ResourceConnection $resourceConn
    )
    {
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
        $this->productCollectionFactory = $productCollectionFactory;
        //$this->productRepository = $productRepository;
        $this->productResource = $productResource;
        $this->resourceConn = $resourceConn;

        $this->mappingTable = $this->getConnection()->getTableName('sinch_restrictedvalue_mapping');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/nick_attribute_test.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
    }

    public function parse($categoryFeaturesFile, $restrictedValuesFile, $productFeaturesFile)
    {
        $this->logger->info("--- Begin Attribute Parse ---");
        $parseStart = $this->microtime_float();
        $this->category_features = $this->csv->getData($categoryFeaturesFile);
        unset($this->category_features[0]); //Unset the first entry as the sinch export files have a header row
        $this->attribute_values = $this->csv->getData($restrictedValuesFile);
        unset($this->attribute_values[0]);
        $this->product_features = $this->csv->getData($productFeaturesFile);
        unset($this->product_features[0]);

        $this->createAttributeGroups();

        //Parse features
        $this->logger->info("Parsing category features file");
        foreach($this->category_features as $feature_row){
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

        //Parse values
        $this->logger->info("Parsing attribute values file");
        foreach($this->attribute_values as $rv_row){
            $this->attributes[$rv_row[1]]["values"][$rv_row[0]] = [
                "text" => $rv_row[2],
                "order" => $rv_row[3]
            ];
        }
        
        //Product -> [RV]
        foreach($this->product_features as $pf_row){
            $this->productAttributeValues[$pf_row[1]][] = $pf_row[2];
        }
        
        //Delete old mapping entries
        $this->logger->info("Deleting old restricted value mapping");
        $this->getConnection()->query("DELETE FROM " . $this->mappingTable);

        //Create or update Magento attributes
        $this->logger->info("Creating or updating Magento attributes");
        foreach($this->attributes as $sinch_id => $data){
            $this->attributeCount += 1;
            try {
                $this->attributeRepository->get(self::ATTRIBUTE_PREFIX . $sinch_id);
                $this->logger->info("Attribute " . self::ATTRIBUTE_PREFIX . $sinch_id . " exists, updating");
            } catch(\Magento\Framework\Exception\NoSuchEntityException $e){
                $this->logger->info("Failed to get " . self::ATTRIBUTE_PREFIX . $sinch_id . ", creating it");
                $this->createAttribute($sinch_id, $data);
            }
            $this->updateAttributeOptions($sinch_id, $data);
        }

        $elapsed = $this->microtime_float() - $parseStart;
        $this->logger->info("--- Completed Attribute parse ---");
        $this->logger->info("Processed a total of " . $this->attributeCount . " attributes and " . $this->optionCount . " options in " . $elapsed . " seconds");
    }

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
            ->setDefaultFrontendLabel($data["name"])
            ->setIsUnique(0)
            ->setIsVisible(1)
            ->setIsVisibleInGrid(0)
            ->setIsVisibleInAdvancedSearch(0)
            ->setIsVisibleOnFront(1)
            ->setIsUsedInGrid(0)
            ->setIsFilterable(1)
            ->setIsFilterableInGrid(0)
            ->setIsFilterableInSearch(0)
            ->setIsSearchable(0)
            ->setIsComparable(0)
            ->setUsedInProductListing(0)
            ->setPosition($data["order"]);
        $this->attributeRepository->save($attribute);

        foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
            $this->logger->info("Assigning attribute " . self::ATTRIBUTE_PREFIX . $sinch_id . " to set and group for set id " . $attributeSetId);
            $this->attributeManagement->assign(
                $attributeSetId,
                $this->attributeGroupIds[$idx],
                self::ATTRIBUTE_PREFIX . $sinch_id,
                $data["order"]
            );
        }

        //return $this->attributeRepository->get(self::ATTRIBUTE_PREFIX . $sinch_id);
    }

    private function updateAttributeOptions($sinch_feature_id, $data)
    {
        $attribute_code = self::ATTRIBUTE_PREFIX . $sinch_feature_id;
        $attribute = $this->attributeRepository->get(self::ATTRIBUTE_PREFIX . $sinch_feature_id);

        //Delete old options
        $items = $this->optionManagement->getItems($attribute_code);
        $this->logger->info("Deleting old options (" . count($items) . ") for attribute " . $attribute_code);
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
                $this->logger->info("Skipping delete of option id " . $id . " as its text matches a rv");
                continue;
            }

            $this->optionManagement->delete($attribute_code, $id);
        }
        
        //Create new options
        $this->logger->info("Create new options (" . count($data["values"]) . ") for attribute " . $attribute_code);
        foreach($data["values"] as $sinch_value_id => $option_data){
            $this->optionCount += 1;

            //Skip adding the option but still map it if it exists
            $existingOptId = $attribute->getSource()->getOptionId($option_data["text"]);
            if(is_numeric($existingOptId)){
                $this->logger->info("Skipping add of rv " . $sinch_value_id . " as it was detected to be present as option id: " . $existingOptId);
                $this->addMapping($sinch_value_id, $sinch_feature_id, $existingOptId);
                continue;
            }

            $option = $this->optionFactory->create()
                ->setLabel($option_data["text"])
                ->setSortOrder($option_data["order"]);

            if(!$this->optionManagement->add($attribute_code, $option)){ //Seems to only return false if the Label exactly matches an existing option
                $this->logger->warn("Failed to add option: " . $sinch_value_id . " - " . $option_data["text"]);
                //throw new \Magento\Framework\Exception\StateException(__("Failed to create option id: %1", $sinch_value_id));
            } else { //Add succeeded
                //Get option id
                $option_id = $attribute->getSource()->getOptionId($option_data["text"]);
                if(!is_numeric($option_id)){
                    $this->logger->warn("Unable to add mapping as we couldn't establish option_id for \"" . $option_data["text"] . "\"");

                    foreach($this->optionManagement->getItems($attribute_code) as $option){
                        if($option->getLabel() == $option_data["text"]){
                            $this->logger->warn("Late option_id match, required manual getItems reload");
                            $this->addMapping($sinch_value_id, $sinch_feature_id, $option->getValue());
                        }
                    }
                } else {
                    //Insert a mapping record for the option
                    $this->addMapping($sinch_value_id, $sinch_feature_id, $option_id);
                }
            }
        }
    }

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
                throw new \Magento\Framework\Exception\StateException(__("An attribute group is missing"));
            }
        }
    }

    private function getAttributeSetIds()
    {
        if($this->attributeSetCache == null){
            $attributeSets = $this->attributeSetRepository->getList(
                $this->searchCriteriaBuilder->create()
            )->getItems();

            if(count($attributeSets) < 1){
                throw new \Magento\Framework\Exception\StateException(__("Retrieved no attribute sets"));
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
                "INSERT IGNORE INTO {$this->mappingTable} (sinch_id, sinch_feature_id, option_id) VALUES(:sinch_id, :sinch_feature_id, :option_id)"
            );
        }

        $this->mappingInsert->bindValue(":sinch_id", $sinch_id, \PDO::PARAM_INT);
        $this->mappingInsert->bindValue(":sinch_feature_id", $sinch_feature_id, \PDO::PARAM_INT);
        $this->mappingInsert->bindValue(":option_id", $option_id, \PDO::PARAM_INT);
        $this->mappingInsert->execute();
        $this->mappingInsert->closeCursor();
    }

    private function queryMappings($rv_ids)
    {
        $placeholders = implode(',', array_fill(0, count($rv_ids), '?'));
        $massMap = $this->getConnection()->prepare(
            "SELECT sinch_feature_id, option_id FROM {$this->mappingTable} WHERE sinch_id IN ($placeholders)"
        );
        $massMap->execute($rv_ids);
        return $massMap->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function applyAttributeValues()
    {
        $applyStart = $this->microtime_float();
        $this->logger->info("--- Begin applying attribute values to products ---");
        //TODO: Implement finding, loading and setting attribute values on products
        $productCollection = $this->productCollectionFactory
            ->create()
            ->setPageSize(self::PRODUCT_PAGE_SIZE)
            ->setCurPage(1);
        
        while(true){
            $productCollection->load();

            foreach($productCollection as $product){
                $sinch_prod_id = $product->getSinchProductId();
                if(empty($sinch_prod_id)){
                    $this->logger->warn("Non sinch product, skipping");
                    continue;
                }
                if(!isset($this->productAttributeValues[$sinch_prod_id])) continue; //Skip sinch products we have nothing to apply to

                $this->productsEdited += 1;

                $valuesMap = $this->queryMappings($this->productAttributeValues[$sinch_prod_id]);
                if($valuesMap === false){
                    $this->logger->err("Failed to retrieve attribute mappings");
                    throw new \Magento\Framework\Exception\StateException(__("Failed to retrieve attribute mappings"));
                }
                foreach($valuesMap as $attrData){
                    $this->valuesSet += 1;
                    //setCustomAttribute currently broken, see https://github.com/magento/magento2/issues/4703
                    $product->setData(self::ATTRIBUTE_PREFIX . $attrData['sinch_feature_id'], $attrData['option_id']);
                    $this->productResource->saveAttribute($product, self::ATTRIBUTE_PREFIX . $attrData['sinch_feature_id']);
                    //Try $productResource->saveAttribute($product, self::ATTRIBUTE_PREFIX . $attrData['attr']);
                    //vs the full save product below. see https://mage2-blog.com/magento-2-speed-up-product-save/
                }
                //$this->productRepository->save($product);
            }

            $page = $productCollection->getCurPage();
            $lastPage = $productCollection->getLastPageNumber();
            $this->logger->info("Page " . $page . "/" . $lastPage . " complete");
            if($lastPage <= $page){
                break;
            }
            $productCollection->setCurPage($page + 1);
        }

        $elapsed = $this->microtime_float() - $applyStart;
        $this->logger->info(
            "--- Completed applying attribute values. Edited " . $this->productsEdited .
            " products, applied " . $this->valuesSet . " values in " . $elapsed . " seconds ---"
        );
    }

    private function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    private function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }
}