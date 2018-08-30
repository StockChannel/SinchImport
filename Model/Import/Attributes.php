<?php

namespace SITC\Sinchimport\Model\Import;

class Attributes {

    const ATTRIBUTE_GROUP_NAME = "Sinch features";
    const ATTRIBUTE_GROUP_SORT = 50;
    const ATTRIBUTE_PREFIX = "sinch_attribute_";

    //CSV parser
    private $csv;

    //private $eavSetup;

    //private $entityTypeId;
    //private $attributeSetId;

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

    private $attributeSetCache = null;
    private $attributeGroupIds = [];

    private $logger;

    
    public function __construct(
        \Magento\Framework\File\Csv $csv,
        //\Magento\Eav\Setup\EavSetup $eavSetup,
        //\Magento\Eav\Model\AttributeRepository $attributeRepository,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        \Magento\Eav\Api\AttributeGroupRepositoryInterface $attributeGroupRepository,
        \Magento\Eav\Api\Data\AttributeInterfaceFactory $attributeFactory,
        \Magento\Eav\Api\Data\AttributeGroupInterfaceFactory $attributeGroupFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Eav\Api\AttributeOptionManagementInterface $optionManagement,
        \Magento\Eav\Api\Data\AttributeOptionInterfaceFactory $optionFactory,
        \Magento\Eav\Api\AttributeSetRepositoryInterface $attributeSetRepository,
        \Magento\Eav\Api\AttributeManagementInterface $attributeManagement
    )
    {
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        //$this->eavSetup = $eavSetup;
        $this->attributeRepository = $attributeRepository;
        $this->attributeGroupRepository = $attributeGroupRepository;
        $this->attributeFactory = $attributeFactory;
        $this->attributeGroupFactory = $attributeGroupFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->optionManagement = $optionManagement;
        $this->optionFactory = $optionFactory;
        $this->attributeSetRepository = $attributeSetRepository;
        $this->attributeManagement = $attributeManagement;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/nick_attribute_test.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
    }

    public function parse($categoryFeaturesFile, $restrictedValuesFile, $productFeaturesFile)
    {
        $this->category_features = $this->csv->getData($categoryFeaturesFile);
        $this->attribute_values = $this->csv->getData($restrictedValuesFile);
        $this->product_features = $this->csv->getData($productFeaturesFile);

        //$this->entityTypeId = $this->eavSetup->getEntityTypeId(\Magento\Catalog\Model\Category::ENTITY);
        //$this->attributeSetId = $this->eavSetup->getDefaultAttributeSetId($entityTypeId);

        //$this->eavSetup->addAttributeGroup($this->entityTypeId, $this->attributeSetId, self::ATTRIBUTE_GROUP_NAME, self::ATTRIBUTE_GROUP_SORT);
        $criteria = $this->searchCriteriaBuilder->addFilter(\Magento\Eav\Api\Data\AttributeGroupInterface::GROUP_NAME, self::ATTRIBUTE_GROUP_NAME, "eq")->create();
        $groups = $this->attributeGroupRepository->getList($criteria)->getItems();

        if(count($groups) < 1){
            foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
                $ag = $this->attributeGroupFactory->create()
                    ->setAttributeGroupName(self::ATTRIBUTE_GROUP_NAME)
                    ->setAttributeSetId($attributeSetId)
                    ->setData('sort_order', self::ATTRIBUTE_GROUP_SORT);
                 $attributeGroup = $this->attributeGroupRepository->save($ag);
                 $this->attributeGroupIds[$idx] = $attributeGroup->getAttributeGroupId();
            }
        } else {
            //$this->attributeGroup = $groups[0];
            foreach($groups as $attributeGroup){
                $setId = $attributeGroup->getAttributeSetId();
                foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
                    if ($setId == $attributeSetId){
                        $this->attributeGroupIds[$idx] = $attributeGroup->getAttributeGroupId();
                    }
                }
            }
            if(count($groups) != count($this->attributeGroupIds)){
                //Missing a group
                //TODO: Add
                throw new \Magento\Framework\Exception\StateException(__("An attribute group is missing"));
            }
        }

        //$groupCode = $this->eavSetup->convertToAttributeGroupCode(self::ATTRIBUTE_GROUP_NAME);
        //$this->attributeGroupId = $this->eavSetup->getAttributeGroupId($this->entityTypeId, $this->attributeSetId, $groupCode);

        //Parse features
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
        foreach($this->attribute_values as $rv_row){
            $this->attributes[$rv_row[1]]["values"][$rv_row[0]] = [
                "text" => $rv_row[2],
                "order" => $rv_row[3]
            ];
        }
        
        
        //Create or update Magento attributes
        foreach($this->attributes as $sinch_id => $data){
            try {
                //$attribute = $this->eavSetup->getAttribute($this->entityTypeId, self::ATTRIBUTE_PREFIX . $sinch_id);
                $attribute = $this->attributeRepository->get(\Magento\Catalog\Model\Category::ENTITY, self::ATTRIBUTE_PREFIX . $sinch_id);
            } catch(\Magento\Framework\Exception\NoSuchEntityException $e){
                $this->logger->info("Failed to get attribute, creating it");
                $attribute = $this->createAttribute($sinch_id, $data);
            }
            $this->updateAttribute($attribute, $data);
        }

        $this->logger->debug(print_r($this->attributes, true));
        $this->logger->info("---Nicka101--- Completed Attribute parse");
    }

    private function createAttribute($sinch_id, $data)
    {
        /*$this->eavSetup->addAttribute(
            \Magento\Catalog\Model\Category::ENTITY,
            self::ATTRIBUTE_PREFIX . $sinch_id,
            [
                'type'     => 'int',
                'label'    => $data["name"],
                'input'    => 'select',
                'visible'  => true,
                'required' => false,
                'global'   => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'group'    => self::ATTRIBUTE_GROUP_NAME,
                'sort_order' => $data["order"],
                'searchable' => false,
                'filterable' => true,
                'comparable' => true,
                'visible_on_front' => true,
                'used_in_product_listing' => true,
                'unique' => false
            ]
        );*/
        $attribute = $this->attributeFactory->create()
            ->setAttributeCode(self::ATTRIBUTE_PREFIX . $sinch_id)
            ->setBackendType('int')
            ->setFrontendInput('select')
            ->setEntityTypeId(\Magento\Catalog\Model\Category::ENTITY)
            ->setIsRequired(false)
            ->setIsUserDefined(false)
            ->setDefaultFrontendLabel($data["name"])
            ->setIsUnique(false)
            ->setData('visible', true)
            ->setData('sort_order', $data["order"])
            ->setData('searchable', false)
            ->setData('filterable', true)
            ->setData('comparable', true)
            ->setData('visible_on_front', true)
            ->setData('used_in_product_listing', true);
        $this->attributeRepository->save($attribute);

        /*$this->eavSetup->addAttributeToGroup(
            $this->entityTypeId,
            $this->attributeSetId,
            $this->attributeGroupId,
            $attribute["attribute_id"],
            $data["order"]
        );*/
        foreach($this->getAttributeSetIds() as $idx => $attributeSetId){
            $this->attributeManagement->assign(
                \Magento\Catalog\Model\Category::ENTITY,
                $attributeSetId,
                $this->attributeGroupIds[$idx],
                self::ATTRIBUTE_PREFIX . $sinch_id,
                $data["order"]
            );
        }

        //return $this->eavSetup->getAttribute($this->entityTypeId, self::ATTRIBUTE_PREFIX . $sinch_id);
        return $this->attributeRepository->get(
            \Magento\Catalog\Model\Category::ENTITY,
            self::ATTRIBUTE_PREFIX . $sinch_id
        );
    }

    private function updateAttribute($attribute, $data)
    {
        //Delete old options
        $items = $this->optionManagement->getItems(\Magento\Catalog\Model\Category::ENTITY, $attribute->getAttributeCode());
        $this->logger->debug("Old options:");
        $this->logger->debug(print_r($items, true));
        foreach($items as $option){
            $this->optionManagement->delete(
                \Magento\Catalog\Model\Category::ENTITY,
                $attribute->getAttributeCode(),
                $option->getValue()
            );
        }
        
        //Create new options
        foreach($data["values"] as $sinch_value_id => $option_data){
            $option = $this->optionFactory->create()
                ->setLabel($option_data["text"])
                ->setValue($sinch_value_id)
                ->setSortOrder($option_data["order"]);

            if(!$this->optionManagement->add(
                    \Magento\Catalog\Model\Category::ENTITY,
                    $attribute->getAttributeCode(),
                    $option
            )){
                $this->logger->error("Failed to add option id: " . $sinch_value_id);
                throw new \Magento\Framework\Exception\StateException(__("Failed to create option id: %1", $sinch_value_id));
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
}