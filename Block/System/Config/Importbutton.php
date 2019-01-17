<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class Importbutton
 * @package SITC\Sinchimport\Block\System\Config
 */
class Importbutton extends \Magento\Config\Block\System\Config\Form\Field
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
        $html = $this->_appendJs();
        $html .= $this->_appendCss();

        $html .= '<div id="sinchimport_status_template" name="sinchimport_status_template" style="display:none">';
        $html .= $this->_getStatusTemplateHtml();
        $html .= '</div>';

        $html .= $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            [
                'label' => 'Force Import Now',
                'id' => 'mb-sinch-import-button',
                'class' => 'mb-start-button',
                'style' => 'margin-top:30px'
            ]
        )->toHtml();

        $lastImportData = $this->sinch->getDataOfLatestImport();
        $lastImportStatus = $lastImportData['global_status_import'];

        $html .= '<div id="sinchimport_current_status_message" name="sinchimport_current_status_message" style="display:true">';
        if ($lastImportStatus == 'Failed') {
            $html .= '<p class="sinch-error">The import has failed. Last step was "'
                . $lastImportData['detail_status_import']
                . '"<br> Error reporting : "'
                . $lastImportData['error_report_message'] . '"</p>';
        } elseif ($lastImportStatus == 'Successful') {
            $html .= '<p class="sinch-success">'
                . $lastImportData['number_of_products']
                . ' products imported succesfully!</p>';
        } elseif ($lastImportStatus == 'Run') {
            $html .= '<p>Import is running now</p>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @return string
     */
    protected function _appendJs()
    {
        $completeIcon = $this->getViewFileUrl(
            'SITC_Sinchimport::images/import_complete.gif'
        );
        $runningIcon = $this->getViewFileUrl(
            'SITC_Sinchimport::images/ajax_running.gif'
        );

        $postUrl = $this->getUrl('sinchimport/ajax');
        $postStockPriceUrl = $this->getUrl('sinchimport/ajax/stockPrice');
        $postUrlUpd = $this->getUrl('sinchimport/ajax/updateStatus');
        $indexingUrl = $this->getUrl('sinchimport/ajax/indexingData');

        $html
            = "
<script>
    require([
        'prototype'
    ], function () {
        var Sinch = Class.create();
        Sinch.prototype = {
            initialize: function() {
                this.postUrl = '" . $postUrl . "';
                this.postStockPriceUrl = '" . $postStockPriceUrl . "';
                this.indexingUrl = '" . $indexingUrl . "';
                this.postUrlUpd = '" . $postUrlUpd . "';
                this.failureUrl = document.URL;
                this.objectMsgs = null;
                // interval object
                this.updateTimer = null;
                // default shipping code. Display on errors

                elem = 'checkoutSteps';
                clickableEntity = '.head';

                // overwrite Accordion class method
                var headers = $$('#' + elem + ' .section ' + clickableEntity);
                headers.each(function(header) {
                    Event.observe(header,'click',this.sectionClicked.bindAsEventListener(this));
                }.bind(this));

                Event.observe($('mb-sinch-import-button'), 'click', this.beforeFullImport.bind(this));
                
                if($('mb-sinch-stock-price-import-button')) {
                    Event.observe($('mb-sinch-stock-price-import-button'), 'click', this.beforeStockPriceImport.bind(this));
                }
                
                if($('mb-sinch-indexing-data-button')) {
                    Event.observe($('mb-sinch-indexing-data-button'), 'click', this.beforeIndexing.bind(this));
                }
            },

            beforeFullImport: function () {
                this.setFullImportRunningIcon();
                status_div = document.getElementById('sinchimport_status_template');
                curr_status_div = document.getElementById('sinchimport_current_status_message');
                curr_status_div.style.display = 'none';
                status_div.style.display = '';
                this.startSinchImport(this.postUrl);
            },

            setFullImportRunningIcon: function () {
                runningIcon='<img src=\"" . $runningIcon . "\"/>';
                document.getElementById('sinchimport_start_import').innerHTML = runningIcon;
                document.getElementById('sinchimport_upload_files').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_categories').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_category_features').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_distributors').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_ean_codes').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_manufacturers').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_related_products').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_product_features').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_products').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_pictures_gallery').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_restricted_values').innerHTML = runningIcon;
                document.getElementById('sinchimport_parse_stock_and_prices').innerHTML = runningIcon;
                document.getElementById('sinchimport_generate_category_filters').innerHTML = runningIcon;
                document.getElementById('sinchimport_indexing_data').innerHTML = runningIcon;
                document.getElementById('sinchimport_finish_import').innerHTML = runningIcon;
            },

            beforeStockPriceImport: function () {
                this.setStockPriceRunningIcon();
                status_div = document.getElementById('sinchimport_stock_price_status_template');
                curr_status_div = document.getElementById('sinchimport_stock_price_current_status_message');
                curr_status_div.style.display = 'none';
                status_div.style.display = '';
                this.startSinchImport(this.postStockPriceUrl);
            },

            setStockPriceRunningIcon: function () {
                runningIcon='<img src=\"" . $runningIcon . "\"/>';
                document.getElementById('sinchimport_stock_price_start_import').innerHTML=runningIcon;
                document.getElementById('sinchimport_stock_price_upload_files').innerHTML=runningIcon;
                document.getElementById('sinchimport_stock_price_parse_products').innerHTML=runningIcon;
                document.getElementById('sinchimport_stock_price_indexing_data').innerHTML=runningIcon;
                document.getElementById('sinchimport_stock_price_finish_import').innerHTML=runningIcon;
            },
            
            beforeIndexing: function () {
                this.setIndexingRunningIcon();
                status_div = document.getElementById('sinchimport_indexing_status_template');
                status_div.style.display = '';
                this.startSinchImport(this.indexingUrl);
            },
            
            setIndexingRunningIcon: function () {
                runningIcon='<img src=\"" . $runningIcon . "\"/>';
                document.getElementById('sinchimport_indexing_data_separately').innerHTML=runningIcon;
            },

            startSinchImport: function (url) {
                _this = this;
                new Ajax.Request(url, {
                    method:'post',
                    parameters: '',
                    requestTimeout: 10,
                    onSuccess: function(transport) {
                        var response = transport.responseText.evalJSON();
                        if (!response.success) {
                            alert(response.message);
                            if(response.reload) {
                                setTimeout(function() {
                                        location.reload();
                                }, 2000);
                            }
                        }
                        _this.updateTimer = setInterval(function(){_this.updateEvent();}, 5000);
                    },
                    onTimeout: function() {
                        setTimeout(function() {
                                location.reload();
                        }, 2000);
                    },
                    onFailure: function() {
                        setTimeout(function() {
                                location.reload();
                        }, 2000);
                    }
                });

            },

            updateEvent: function () {
                _this = this;
                new Ajax.Request(this.postUrlUpd, {
                    method: 'post',
                    parameters: {},
                    onSuccess: function(transport) {
                        _this.objectMsgs = transport.responseText.evalJSON();
                        if(_this.objectMsgs.length){
                            _this.objectMsgs.forEach(function (objectMsg) {
                                if (objectMsg.finished == 1) {
                                    _this.updateStatusHtml(objectMsg);
                                    _this.clearUpdateInterval();
                                } else {
                                    _this.updateStatusHtml(objectMsg);
                                }
                            });
                        }
                    },
                    onFailure: this.ajaxFailure.bind(),
                });
            },

            updateStatusHtml: function(objectMsg){
                message = objectMsg.message.toLowerCase();
                mess_id = 'sinchimport_'+message.replace(/\s+/g, '_');
                if(document.getElementById(mess_id)){
                    $(mess_id).innerHTML = '<img src=\"" . $completeIcon . "\"/>';
                }
                html = $('sinchimport_status_template').innerHTML;
            },

            ajaxFailure: function(){
                this.clearUpdateInterval();
                location.href = this.failureUrl;
            },

            clearUpdateInterval: function () {
                clearInterval(this.updateTimer);
            }
        }

        sinchImport = new Sinch();
    });
</script>
        ";

        return $html;
    }

    /**
     * @return string
     */
    protected function _appendCss()
    {
        $html = '';

        // Add style for page in here
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
            <td style='padding:10px 5px; nowrap=''>Start Import</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_start_import'>
                    <img src='" . $runningIcon . "' alt='Start Import' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Download Files</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_upload_files'>
                    <img src='" . $runningIcon . "' alt='Download Files' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Categories</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_categories'>
                    <img src='" . $runningIcon . "' alt='Parse Categories' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Category Features</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_category_features'>
                    <img src='" . $runningIcon . "' alt='Parse Category Features' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Distributors</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_distributors'>
                    <img src='" . $runningIcon . "' alt='Parse Distributors' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse EAN Codes</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_ean_codes'>
                    <img src='" . $runningIcon . "' alt='Parse EAN Codes' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Manufacturers</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_manufacturers'>
                    <img src='" . $runningIcon . "' alt='Parse Manufacturers' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Related Products</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_related_products'>
                    <img src='" . $runningIcon . "' alt='Parse Related Products' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Product Features</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_product_features'>
                    <img src='" . $runningIcon . "' alt='Parse Product Features' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Products</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_products'>
                    <img src='" . $runningIcon . "' alt='Parse Products' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Pictures Gallery</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_pictures_gallery'>
                    <img src='" . $runningIcon . "' alt='Parse Pictures Gallery' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Restricted Values</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_restricted_values'>
                    <img src='" . $runningIcon . "' alt='Parse Restricted Values' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Parse Stock And Prices</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_parse_stock_and_prices'>
                    <img src='" . $runningIcon . "' alt='Parse Stock And Prices' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Generate Category Filters</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_generate_category_filters'>
                    <img src='" . $runningIcon . "' alt='Generate Category Filters' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Indexing Data</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_indexing_data'>
                    <img src='" . $runningIcon . "' alt='Indexing Data' />
               </span>
            </td>
        </tr>
        <tr>
            <td style='padding:10px 5px;' nowrap=''>Import Finished</td>
            <td style='padding:10px 5px;'>
                <span id='sinchimport_finish_import'>
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
