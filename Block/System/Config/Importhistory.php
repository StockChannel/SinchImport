<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Block\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

class Importhistory extends \Magento\Config\Block\System\Config\Form\Field
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
     * @codeCoverageIgnore
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $html = $this->_appendCss();
        
        $lastSuccessImport = $this->sinch->getDateOfLatestSuccessImport();
        $importHistory     = $this->sinch->getImportStatusHistory();
        
        $cssArr = [
            'Failed'     => 'sinch-error',
            'Run'        => 'sinch-run',
            'Successful' => 'sinch-success'
        ];
        
        $html
            .= '
<!--Table for import history-->
<div class="comment last-import-info">' . ($lastSuccessImport
                ? "Your last successful feed import was at "
                . $lastSuccessImport
                : "Your import never finished with success") . '</div>
<table class="data-table history mb-sinch-history">
    <thead>
        <tr>
            <th style="padding:15px 5px;background-color:#ccc;font-size:11pt;">Import Start</th>
            <th style="padding:15px 5px;background-color:#ccc;font-size:11pt;">Import Finish</th>
            <th style="padding:15px 5px;background-color:#ccc;font-size:11pt;" nowrap>Import Type</th>
            <th style="padding:15px 5px;background-color:#ccc;font-size:11pt;">Status</th>
            <th style="padding:15px 5px;background-color:#ccc;font-size:11pt;" nowrap>Number of products</th>
        </tr>
    </thead>
    <tbody>
        ';
        
        foreach ($importHistory as $item) {
            $html
                .= '
        <tr>
            <td  style="padding:10px 5px;" nowrap>' . $item['start_import'] . '</td>
            <td  style="padding:10px 5px;" nowrap>' . $item['finish_import'] . '</td>
            <td  style="padding:10px 5px;" nowrap>' . $item['import_type'] . '</td>
            <td  style="padding:10px 5px;" class="'
                . $cssArr[$item['global_status_import']] . '">'
                . $item['global_status_import'] . '</td>
            <td  style="padding:10px 5px;">' . $item['number_of_products'] . '</td>
        </tr>
            ';
        }
        $html
            .= '
    </tbody>
</table>
        ';
        
        return $html;
    }
    
    protected function _appendCss()
    {
        $html
            = '
<style type="text/css">
    table.data-table.history.mb-sinch-history td,
    table.data-table.history.mb-sinch-history th {
        border: 1px solid rgba(51, 51, 51, 0.05);
        text-align: center;
    }

    .admin__collapsible-block .last-import-info {
        color: green;
        font-weight: bold;
        margin-bottom: 30px;
    }

    .sinch-error {
        font-weight: bold;
        color: #D40707 ;
        margin: 5px 0;
    }

    .sinch-success {
        color: green;
        font-weight: bold;
        margin: 5px 0;
    }

    .sinch-run {
        color: blue;
        font-weight: bold;
        text-align: center;
        margin: 5px 0;
    }


    table.history {
        border-collapse: collapse;
        width: 100%;
    }

    table.history th {
        border: solid 1px #6F8992;
        background-color: #6F8992;
        color: #fff;
        font-weight: bold;
        padding: 2px 3px;
    }

    table.history td {
        border: 1px solid #333;
        padding: 2px 3px;
    }
</style>
        ';
        
        return $html;
    }
}
