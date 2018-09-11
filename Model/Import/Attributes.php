<?php

namespace SITC\Sinchimport\Model\Import;

class Attributes {

    const ATTRIBUTE_GROUP_NAME = "Sinch features";
    const ATTRIBUTE_GROUP_SORT = 50;
    const ATTRIBUTE_PREFIX = "sinch_attribute_";

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

    private $attributeRepository;
    private $attributeGroupRepository;
    private $attributeFactory;
    private $attributeGroupFactory;
    private $searchCriteriaBuilder;
    private $optionManagement;
    private $optionFactory;
    private $attributeSetRepository;
    private $attributeManagement;
    private $mappingFactory;

    private $attributeSetCache = null;
    private $attributeGroupIds = [];

    private $logger;

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
        \SITC\Sinchimport\Model\Import\Mapping\RestrictedValueMappingFactory $mappingFactory
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
        $this->mappingFactory = $mappingFactory;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/nick_attribute_test.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
    }

    public function parse($categoryFeaturesFile, $restrictedValuesFile, $productFeaturesFile)
    {
        $this->category_features = $this->csv->getData($categoryFeaturesFile);
        unset($this->category_features[0]); //Unset the first entry as the sinch export files have a header row
        $this->attribute_values = $this->csv->getData($restrictedValuesFile);
        unset($this->attribute_values[0]);
        $this->product_features = $this->csv->getData($productFeaturesFile);
        unset($this->product_features[0]);

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
        
        
        //Create or update Magento attributes
        $this->logger->info("Creating or updating Magento attributes");
        foreach($this->attributes as $sinch_id => $data){
            try {
                $this->attributeRepository->get(self::ATTRIBUTE_PREFIX . $sinch_id);
                $this->logger->info("Attribute " . self::ATTRIBUTE_PREFIX . $sinch_id . " exists, updating");
            } catch(\Magento\Framework\Exception\NoSuchEntityException $e){
                $this->logger->info("Failed to get attribute, creating it");
                $this->createAttribute($sinch_id, $data);
            }
            $this->updateAttributeOptions($sinch_id, $data);
        }

        $this->logger->info("---Nicka101--- Completed Attribute parse");
    }

    private function createAttribute($sinch_id, $data)
    {
        $this->logger->info("Creating attribute " . self::ATTRIBUTE_PREFIX . $sinch_id);
        $attribute = $this->attributeFactory->create()
            ->setEntityTypeId(\Magento\Catalog\Model\Product::ENTITY)
            ->setAttributeCode(self::ATTRIBUTE_PREFIX . $sinch_id)
            ->setBackendType('int')
            ->setFrontendInput('select')
            ->setIsRequired(false)
            ->setIsUserDefined(false)
            ->setDefaultFrontendLabel($data["name"])
            ->setIsUnique(false)
            ->setIsVisible(true)
            ->setData('sort_order', $data["order"])
            ->setIsUsedInGrid(true)
            ->setIsVisibleInGrid(true)
            ->setIsFilterableInGrid(true)
            ->setIsFilterableInSearch(true)
            ->setIsSearchable(false)
            ->setIsVisibleInAdvancedSearch(false)
            ->setIsComparable(true)
            ->setData('visible_on_front', true)
            ->setIsVisibleOnFront(true)
            ->setData('used_in_product_listing', true)
            ->setUsedInProductListing(true);
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
        //Delete old options
        $items = $this->optionManagement->getItems($attribute_code);

        //Delete old mapping entries
        $this->logger->info("Deleting old restricted value mapping");
        $this->mappingFactory
            ->create()
            ->getCollection()
            ->walk('delete');

        $this->logger->info("Deleting old options (" . count($items) . ") for attribute " . $attribute_code);
        foreach($items as $option){
            $id = $option->getValue();
            if($id == '') continue; //Magento seems to add an empty option to the array, ignore it
            $this->optionManagement->delete($attribute_code, $id);
        }
        
        //Create new options
        $this->logger->info("Create new options (" . count($data["values"]) . ") for attribute " . $attribute_code);
        foreach($data["values"] as $sinch_value_id => $option_data){
            $option = $this->optionFactory->create()
                ->setLabel($option_data["text"])
                //->setValue($sinch_value_id) //TODO: Don't set value (i.e. option id), use an assigned one and keep a mapping handy
                ->setSortOrder($option_data["order"]);

            if(!$this->optionManagement->add($attribute_code, $option)){
                $this->logger->err("Failed to add option id: " . $sinch_value_id);
                throw new \Magento\Framework\Exception\StateException(__("Failed to create option id: %1", $sinch_value_id));
            }
            
            //Insert a mapping record for the option
            $this->mappingFactory->create()->setData([
                'sinch_id' => $sinch_value_id,
                'sinch_feature_id' => $sinch_feature_id,
                'option_id' => $option->getValue() //?
            ])->save();
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
}