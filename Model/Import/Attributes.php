<?php

namespace SITC\Sinchimport\Model\Import;

class Attributes {

    //CSV parser
    private $csv;
    private $eavConfig;
    private $eavSetup;
    private $attributeRepository;

    //ID, CategoryID, Name, Order
    private $category_features;
    //ID, CategoryFeatureID, Text, Order
    private $attribute_values;
    //ID, ProductID, RestrictedValueID
    private $product_features;


    //Attributes to produce
    private $attributes;

    
    public function __construct(
        \Magento\Framework\File\Csv $csv,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Eav\Setup\EavSetup $eavSetup,
        \Magento\Eav\Model\AttributeRepository $attributeRepository
    )
    {
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        $this->eavConfig = $eavConfig;
        $this->eavSetup = $eavSetup;
        $this->attributeRepository = $attributeRepository;
    }

    public function parse($categoryFeaturesFile, $restrictedValuesFile, $productFeaturesFile)
    {
        $this->category_features = $this->csv->getData($categoryFeaturesFile);
        $this->attribute_values = $this->csv->getData($restrictedValuesFile);
        $this->product_features = $this->csv->getData($productFeaturesFile);

        foreach($this->category_features as $feature_row){
            if(count($feature_row) != 4) {
                print("Feature row not 4 columns");
                print_r($feature_row);
                continue;
            }
            $this->attributes[$feature_row[0]] = [
                "catId" => $feature_row[1],
                "name" => $feature_row[2],
                "order" => $feature_row[3],
                "values" => []
            ];
        }

        foreach($this->attribute_values as $rv_row){
            $this->attributes[$rv_row[1]]["values"][] = [
                "text" => $rv_row[2],
                "order" => $rv_row[3]
            ];
        }
        print_r($this->attributes);
        print("---Nicka101--- Completed Attribute parse");
    }
}