<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Block\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

class Stockpriceimportbutton extends \Magento\Config\Block\System\Config\Form\Field
{
    protected $sinch;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param array                                   $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magebuzz\Sinchimport\Model\Sinch $sinch,
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
        $html .= '<div id="sinchimport_stock_price_status_template" name="sinchimport_stock_price_status_template" style="display:none">';
        $html .= $this->_getStatusTemplateHtml();
        $html .= '</div>';

        $startImportButtonHtml = $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            ['label' => 'Force Stock & Prices Import Now',
             'id'    => 'mb-sinch-stock-price-import-button',
             'class' => 'mb-start-button', 'style' => 'margin-top:30px']
        )->toHtml();

        $safe_mode_set = ini_get('safe_mode');

        if ($safe_mode_set) {
            $html .= "<p class='sinch-error'><b>You can't start import (safe_mode is 'On'. set safe_mode = Off in php.ini )<b></p>";
        } elseif (! $this->sinch->isFullImportHaveBeenRun()) {
            $html .= "Full import have never finished with success";
        } else {
            $html .= $startImportButtonHtml;
        }

        $lastImportData = $this->sinch->getDataOfLatestImport();
        $lastImportStatus = $lastImportData['global_status_import'];

        if ($lastImportStatus == 'Failed') {
            $html .= '<div id="sinchimport_stock_price_current_status_message" name="sinchimport_stock_price_current_status_message" style="display:true"><br><br><hr/><p class="sinch-error">The import has failed. Please ensure that you are using the correct settings. Last step was "'
                . $lastImportData['detail_status_import']
                . '"<br> Error reporting : "'
                . $lastImportData['error_report_message'] . '"</p></div>';
        } elseif ($lastImportStatus == 'Successful') {
            $html .= '<div id="sinchimport_stock_price_current_status_message" name="sinchimport_stock_price_current_status_message" style="display:true"><br><br><hr/><p class="sinch-success">'
                . $lastImportData['number_of_products']
                . ' products imported succesfully!</p></div>';
        } elseif ($lastImportStatus == 'Run') {
            $html .= '<div id="sinchimport_stock_price_current_status_message" name="sinchimport_stock_price_current_status_message" style="display:true"><br><br><hr/><p>Import is running now</p></div>';
        } else {
            $html .= '<div id="sinchimport_stock_price_current_status_message" name="sinchimport_stock_price_current_status_message" style="display:true"></div>';
        }

        return $html;
    }

    protected function _getStatusTemplateHtml()
    {
        $runningIcon = $this->getViewFileUrl(
            'Magebuzz_Sinchimport::images/ajax_running.gif'
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
            <td style='padding:10px 5px; nowrap=''>Start Stock & Price Import</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_stock_price_start_import'>
                    <img src='" . $runningIcon . "' alt='Start Stock & Price Import' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Download Files</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_stock_price_upload_files'>
                    <img src='" . $runningIcon . "' alt='Download Files' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Stock And Prices</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_stock_price_parse_products'>
                    <img src='" . $runningIcon . "' alt='Parse Stock And Prices' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Indexing Data</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_stock_price_indexing_data'>
                    <img src='" . $runningIcon . "' alt='Indexing Data' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Import Finished</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_stock_price_finish_import'>
                    <img src='" . $runningIcon . "' alt='Import Finished' />
               </span>
            </td>
        </tr>
    </tbody>
</table>
        ";

        return $html;
    }
}
