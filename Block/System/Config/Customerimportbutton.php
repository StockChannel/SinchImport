<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class Customerimportbutton
 * @package SITC\Sinchimport\Block\System\Config
 */
class Customerimportbutton extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * @var \SITC\Sinchimport\Model\Sinch
     */
    protected $sinch;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array $data
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
        $html .= '<div id="sinchimport_customer_groups_price_status_template" name="sinchimport_customer_groups_price_status_template" style="display:none">';
        $html .= $this->_getStatusTemplateHtml();
        $html .= '</div>';

        $startImportButtonHtml = $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            [
                'label' => 'Force Customer Groups & Prices Import Now',
                'id' => 'mb-sinch-customer-groups-price-import-button',
                'class' => 'mb-start-button',
                'style' => 'margin-top:30px'
            ]
        )->toHtml();

        $safe_mode_set = ini_get('safe_mode');

        if ($safe_mode_set) {
            $html .= "<p class='sinch-error'><b>You can't start import (safe_mode is 'On'. set safe_mode = Off in php.ini )<b></p>";
        } elseif (!$this->sinch->isFullImportHaveBeenRun()) {
            $html .= "Full import have never finished with success";
        } else {
            $html .= $startImportButtonHtml;
        }

        $lastImportData = $this->sinch->getDataOfLatestImport();

        if (!empty($lastImportData) && $lastImportData['import_type'] == 'CUSTOMER GROUPS PRICE') {
            $lastImportStatus = $lastImportData['global_status_import'];
            if ($lastImportStatus == 'Failed') {
                $html .= '<div id="sinchimport_customer_groups_price_current_status_message" name="sinchimport_customer_groups_price_current_status_message" style="display:true"><br><br><hr/><p class="sinch-error">The import has failed. Please ensure that you are using the correct settings. Last step was "'
                    . $lastImportData['detail_status_import']
                    . '"<br> Error reporting : "'
                    . $lastImportData['error_report_message'] . '"</p></div>';
            } elseif ($lastImportStatus == 'Successful') {
                $html .= '<div id="sinchimport_customer_groups_price_current_status_message" name="sinchimport_customer_groups_price_current_status_message" style="display:true"><br><br><hr/><p class="sinch-success">'
                    . $lastImportData['number_of_products']
                    . ' customer group imported succesfully!</p></div>';
            } elseif ($lastImportStatus == 'Run') {
                $html .= '<div id="sinchimport_customer_groups_price_current_status_message" name="sinchimport_customer_groups_price_current_status_message" style="display:true"><br><br><hr/><p class="sinch-processing">Import is running now</p></div>';
            } else {
                $html .= '<div id="sinchimport_customer_groups_price_current_status_message" name="sinchimport_customer_groups_price_current_status_message" style="display:true"></div>';
            }
        } else {
            $html .= '<div id="sinchimport_customer_groups_price_current_status_message" name="sinchimport_customer_groups_price_current_status_message" style="display:true"></div>';
        }

        return $html;
    }

    /**
     * @return string
     */
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
            <td style='padding:10px 5px; nowrap=''>Start Customer Group & Price Import</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_customer_groups_price_start_import'>
                    <img src='" . $runningIcon . "' alt='Start Customer Groups & Price Import' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Download Files</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_customer_groups_price_upload_files'>
                    <img src='" . $runningIcon . "' alt='Download Files' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Customer Group And Prices</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_customer_groups_price_parse_products'>
                    <img src='" . $runningIcon . "' alt='Parse Customer Groups And Prices' />
               </span>
            </td>
        </tr>
    </tbody>
</table>
        ";
        return $html;
    }
}
