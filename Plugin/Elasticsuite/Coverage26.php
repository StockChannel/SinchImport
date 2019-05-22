<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;

/**
 * This class implements compatibility for the Sinch filters to be excluded from Elasticsuite 2.6.x's and 2.7.x's "Facet Min Coverage"
 */
class Coverage26 {
    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper
    ){
        $this->helper = $helper;
    }

    /**
     * Makes sure that Elasticsearch doesn't exclude Sinch filters
     * 
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param \Smile\ElasticsuiteCatalog\Model\Layer\RelevantFilterList $subject
     * @param \Magento\Catalog\Model\Layer\Filter\FilterInterface[] $result The original result
     * @param \Magento\Catalog\Model\Layer $layer
     * @param \Magento\Catalog\Model\Layer\Filter\FilterInterface[] $filters
     * 
     * @return \Magento\Catalog\Model\Layer\Filter\FilterInterface[]
     */
    public function afterGetRelevantFilters(
        \Smile\ElasticsuiteCatalog\Model\Layer\RelevantFilterList $subject,
        $result,
        \Magento\Catalog\Model\Layer $layer,
        array $filters
    ){
        if($this->helper->getStoreConfig('sinchimport/attributes/override_elasticsuite') != 1){
            //Leave ElasticSuite to do its thing
            return $result;
        }

        foreach($filters as $filter){
            try {
                $attribute = $filter->getAttributeModel();
                if(strpos($attribute->getAttributeCode(), \SITC\Sinchimport\Model\Import\Attributes::ATTRIBUTE_PREFIX) === 0 &&
                    !in_array($filter, $result)) {
                    $result[] = $filter;
                }
            } catch (\Exception $e) {
                ;
            }
        }

        return $result;
    }
}