<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Core\Block\System\Config;

class Information extends \Magento\Config\Block\System\Config\Form\Fieldset
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

		$html .= $this->_getInfo();

		$html .= $this->_getFooterHtml($element);

		return $html;
    }

	protected function _getInfo()
	{
		$html = '<div class="support-info">';
		$html .= '	<h3>Support Policy</h3>';
		$html .= '	<p>We provide 6 months free support for all of our extensions and templates. We are not responsible for any bug or issue caused of your changes to our products. To report a bug, you can easily go to <a href="http://www.magebuzz.com/support/" title="Magebuzz Support" target="_blank">our Support Page</a>, email, call or submit a ticket.</p>';
		$html .= '	<h3>Read the blog</h3>';
		$html .= '	<p>The <a href="http://www.magebuzz.com/blog/" target="_blank">Magebuzz Blog</a> is updated regularly with Magento tutorials, Magebuzz new products, updates, promotions... Visit <a href="http://www.magebuzz.com/blog/" target="_blank">Magebuzz Blog</a> recently to be kept updated.</p>';
		$html .= '	<h3>Follow Us</h3>';
		$html .= '	<div class="magebuzz-follow"><ul><li class="facebook"><a href="http://www.facebook.com/MageBuzz" title="Facebook" target="_blank"><img src="' . $this->getViewFileUrl('Magebuzz_Core::images/facebook.png') . '" alt="Facebook"/></a></li><li class="twitter"><a href="https://twitter.com/MageBuzz" title="Twitter" target="_blank"><img src="' . $this->getViewFileUrl('Magebuzz_Core::images/twitter.png') . '" alt="Twitter"/></a></li></ul></div>';
		$html .= '</div>';

		return $html;
	}
}