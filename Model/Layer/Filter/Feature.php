<?php

namespace SITC\Sinchimport\Model\Layer\Filter;

/**
 * Layer category filter
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
class Feature extends \Magento\Catalog\Model\Layer\Filter\AbstractFilter
{
    const LESS = 1;
    const GREATER = 2;
    
    protected $_featureResource;
    
    /**
     * Filter factory
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;
    
    /**
     * Construct
     *
     * @param \Magento\Catalog\Model\Layer\Filter\ItemFactory      $filterItemFactory
     * @param \Magento\Store\Model\StoreManagerInterface           $storeManager
     * @param \Magento\Catalog\Model\Layer                         $layer
     * @param \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder $itemDataBuilder
     * @param \Magento\Framework\Escaper                           $escaper
     * @param array                                                $data
     */
    public function __construct(
        \Magento\Catalog\Model\Layer\Filter\ItemFactory $filterItemFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\Layer $layer,
        \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder $itemDataBuilder,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory, $storeManager, $layer, $itemDataBuilder, $data
        );
        $this->objectManager = $objectManager;
    }
    
    public function setFeatureModel($feature)
    {
        $this->setRequestVar('feature_' . $feature['category_feature_id']);
        $this->setData('feature_model', $feature);
        
        return $this;
    }
    
    /**
     * Apply category filter to layer
     *
     * @param   \Magento\Framework\App\RequestInterface $request
     *
     * @return  $this
     */
    public function apply(\Magento\Framework\App\RequestInterface $request)
    {
        $filter = $request->getParam($this->_requestVar);
        if (is_array($filter)) {
            return $this;
        }
        
        $text = $this->_getOptionText($filter);
        if ($filter && $text) {
            $this->_getResource()->applyFilterToCollection($this, $filter);
            $this->getLayer()->getState()->addFilter(
                $this->_createItem($text, $filter)
            );
            $this->_items = [];
        }
        
        return $this;
    }
    
    /**
     * Get option text from frontend model by option id
     *
     * @param   int $optionId
     *
     * @return  integer
     */
    protected function _getOptionText($optionId)
    {
        return $optionId;
    }
    
    /**
     * Retrieve resource instance
     *
     * @return \SITC\Sinchimport\Model\ResourceModel\Layer\Filter\Feature
     */
    protected function _getResource()
    {
        if (is_null($this->_featureResource)) {
            $this->_featureResource = $this->objectManager->create(
                'SITC\Sinchimport\Model\ResourceModel\Layer\Filter\Feature'
            );
        }
        
        return $this->_featureResource;
    }
    
    /**
     * Get filter name
     *
     * @return \Magento\Framework\Phrase
     */
    public function getName()
    {
        $attribute = $this->getFeatureModel();
        
        return $attribute['name'];
    }
    
    public function getFeatureModel()
    {
        $feature = $this->getData('feature_model');
        if ($feature === null) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('The feature model is not defined.')
            );
        }
        
        return $feature;
    }
    
    /**
     * Get data array for building attribute filter items
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return array
     */
    protected function _getItemsData()
    {
        \Magento\Framework\Profiler::start(__METHOD__);
        
        $feature           = $this->getFeatureModel();
        $this->_requestVar = 'feature_' . $feature['category_feature_id'];
        $limitDirection    = isset($feature['limit_direction'])
            ? $feature['limit_direction'] : 0;
        
        $data    = [];
        $options = explode("\n", $feature['restricted_values']);
        if (count($options) == 0) {
            \Magento\Framework\Profiler::stop(__METHOD__);
            
            return $data;
        }
        if (isset($feature['order_val']) && $feature['order_val'] == '2') {
            $options = array_reverse($options);
        }
        if ($limitDirection != self::LESS && $limitDirection != self::GREATER) {
            
            $optionsCount = $this->_getResource()->getCount($this);
            foreach ($options as $option) {
                if ($pos = strpos($option, '::')) {
                    $value              = substr($option, 0, $pos);
                    $presentation_value = substr($option, $pos + 2);
                } else {
                    $value = $presentation_value = $option;
                }
                if (isset($optionsCount[$value]) && $optionsCount[$value] > 0) {
                    $data[] = array(
                        'label' => $presentation_value,
                        'value' => $value,
                        'count' => $optionsCount[$value],
                    );
                }
            }
        } else {
            $oCount    = count($options);
            $intervals = [];
            if ($feature['order_val'] == '2') {
                for ($i = 0; $i < $oCount - 1; $i++) {
                    $intervals[$i]['high'] = $options[$i];
                    $intervals[$i]['low']  = $options[$i + 1];
                }
            } else {
                for ($i = 0; $i < $oCount - 1; $i++) {
                    $intervals[$i]['low']  = $options[$i];
                    $intervals[$i]['high'] = $options[$i + 1];
                }
            }
            
            if ($feature['order_val'] == '2') {
                array_push(
                    $intervals, array(
                    'high' => $options[$oCount - 1],
                )
                );
                array_unshift(
                    $intervals, array(
                    'low' => $options[0],
                )
                );
            } else {
                array_push(
                    $intervals, array(
                    'low' => $options[$oCount - 1],
                )
                );
                array_unshift(
                    $intervals, array(
                    'high' => $options[0],
                )
                );
            }
            
            $this->setData('intervals', $intervals);
            
            $defaultSign = $feature['default_sign'];
            for ($i = 0; $i < count($intervals); $i++) {
                if ($feature['order_val'] == '2') {
                    $interval = $intervals[$i];
                    $label    = isset($interval['high']) ? $interval['high']
                        . " $defaultSign" : '>';
                    if ($label == '>' && isset($intervals[$i + 1])) {
                        $pad   = strlen(
                                $intervals[$i + 1]['high'] . $defaultSign
                            ) + 2;
                        $label = str_pad($label, $pad * 2, ' ', STR_PAD_LEFT);
                        $label = str_replace(' ', '&nbsp', $label);
                    }
                    $label .= isset($interval['high'], $interval['high'])
                        ? ' - ' : ' ';
                    $label .= isset($interval['low']) ? $interval['low']
                        . " $defaultSign" : '>';
                    $value = isset($interval['low']) ? $interval['low'] : '-';
                    $value .= ',';
                    $value .= isset($interval['high']) ? $interval['high']
                        : '-';
                    if (isset($interval['high'])
                        AND ! isset($interval['low'])
                    ) {
                        $value = '-,' . $interval['high'];
                    }
                    if ($this->_getResource()->getIntervalsCountDescending(
                            $this, $interval
                        ) > 0
                    ) {
                        $data[] = array(
                            'label' => $label,
                            'value' => $value,
                            'count' => $this->_getResource()
                                ->getIntervalsCountDescending($this, $interval),
                        );
                    }
                } else {
                    $interval = $intervals[$i];
                    $label    = isset($interval['low']) ? $interval['low']
                        . " $defaultSign" : '<';
                    if ($label == '<' && isset($intervals[$i + 1])) {
                        $pad   = strlen(
                                $intervals[$i + 1]['low'] . $defaultSign
                            ) + 2;
                        $label = str_pad($label, $pad * 2, ' ', STR_PAD_LEFT);
                        $label = str_replace(' ', '&nbsp', $label);
                    }
                    $label .= isset($interval['low'], $interval['high']) ? ' - '
                        : ' ';
                    $label .= isset($interval['high']) ? $interval['high']
                        . " $defaultSign" : '<';
                    
                    $value = isset($interval['low']) ? $interval['low'] : '-';
                    $value .= ',';
                    $value .= isset($interval['high']) ? $interval['high']
                        : '-';
                    if ($this->_getResource()->getIntervalsCount(
                            $this, $interval
                        ) > 0
                    ) {
                        $data[] = array(
                            'label' => $label,
                            'value' => $value,
                            'count' => $this->_getResource()->getIntervalsCount(
                                $this, $interval
                            ),
                        );
                    }
                }
            }
        }
        
        \Magento\Framework\Profiler::stop(__METHOD__);
        
        foreach ($data as $key => $itemData) {
            $this->itemDataBuilder->addItemData(
                $itemData['label'],
                $itemData['value'],
                $itemData['count']
            );
        }
        
        return $this->itemDataBuilder->build();
    }
}