<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

class Indexingbutton extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $sinch;
    
    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array                                   $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \SITC\Sinchimport\Model\Sinch $sinch,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->sinch = $sinch;
    }
    
    /**
     * @param AbstractElement $element
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @codeCoverageIgnore
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $html = '';
        $html .= '<div id="sinchimport_indexing_status_template" name="sinchimport_indexing_status_template" style="display:none">';
        $html .= $this->_getStatusTemplateHtml();
        $html .= '</div>';
        
        $html .= $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            ['label' => 'Indexing data',
             'id'    => 'mb-sinch-indexing-data-button',
             'class' => 'mb-indexing-button', 'style' => 'margin-top:30px']
        )->toHtml();
        
        return $html;
    }
    
    protected function _getStatusTemplateHtml()
    {
        $runningIcon = $this->getViewFileUrl(
            'SITC_Sinchimport::images/ajax_running.gif'
        );
        
        $html
            = "
<table class='data-table history'>
    <thead>
        <tr>
            <th style='text-align:left;padding:15px 5px;background-color:#ccc;font-size:12pt;'>Progress</th>
            <th style='text-align:left;padding:15px 5px;background-color:#ccc;font-size:12pt;'>Status</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Indexing Data</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_indexing_data_separately'>
                    <img src='" . $runningIcon . "' alt='Indexing Data' />
               </span>
            </td>
        </tr>
    </tbody>
</table>
        ";
        
        return $html;
    }
}
