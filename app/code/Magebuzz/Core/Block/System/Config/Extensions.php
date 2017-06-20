<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Core\Block\System\Config;

class Extensions extends \Magento\Config\Block\System\Config\Form\Fieldset
{
    /**
     * @var \Magento\Config\Block\System\Config\Form\Field
     */
    protected $_fieldRenderer;

    /**
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @var \Magento\Framework\Module\ModuleResource
     */
    private $moduleResource;

    /**
     * @param \Magento\Backend\Block\Context $context
     * @param \Magento\Backend\Model\Auth\Session $authSession
     * @param \Magento\Framework\View\Helper\Js $jsHelper
     * @param \Magento\Framework\Module\ModuleListInterface $moduleList
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Context $context,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\View\Helper\Js $jsHelper,
        \Magento\Framework\Module\ModuleListInterface $moduleList,
        \Magento\Framework\Module\ModuleResource $moduleResource,
        array $data = []
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data);
        $this->_moduleList = $moduleList;
        $this->moduleResource = $moduleResource;
    }

    /**
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     */
    public function render(\Magento\Framework\Data\Form\Element\AbstractElement $element)
    {
        $html = $this->_getHeaderHtml($element);

        $modules = $this->_moduleList->getNames();

        $dispatchResult = new \Magento\Framework\DataObject($modules);

        $modules = $dispatchResult->toArray();

        sort($modules);

        foreach ($modules as $moduleName) {
        	if (strstr($moduleName, 'Magebuzz_') === false) {
				continue;
			}
            if ($moduleName === 'Magebuzz_Core') {
                continue;
            }
            $html .= $this->_getFieldHtml($element, $moduleName);
        }
        $html .= $this->_getFooterHtml($element);

        return $html;
    }

	protected function _getFieldHtml($fieldset, $moduleCode)
	{
		$currentVer = $this->moduleResource->getDataVersion($moduleCode);

		if (!$currentVer) {
			return '';
		}

		$moduleName = substr($moduleCode, strpos($moduleCode, '_') + 1);

		$status = '<a  target="_blank"><img src="'.$this->getViewFileUrl('Magebuzz_Core::images/ok.gif').'" title="'.__("Installed").'"/></a>';

		$moduleName = '<span class="extension-name">' . $moduleName . '</span>';

		$moduleName = $status . ' ' . $moduleName;

		$field = $fieldset->addField($moduleCode, 'label', array(
				'name'  => 'dummy',
				'label' => $moduleName,
				'value' => $currentVer,
		))->setRenderer($this->_getFieldRenderer());

		return $field->toHtml();
	}

	/**
     * @return \Magento\Config\Block\System\Config\Form\Field
     */
    protected function _getFieldRenderer()
    {
        if (empty($this->_fieldRenderer)) {
            $this->_fieldRenderer = $this->_layout->getBlockSingleton(
                'Magento\Config\Block\System\Config\Form\Field'
            );
        }
        return $this->_fieldRenderer;
    }
}