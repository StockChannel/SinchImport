<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

class Importbutton extends \Magento\Config\Block\System\Config\Form\Field
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
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = $this->_appendJs();
        $html .= $this->_appendCss();

        $html .= '<div id="sinchimport_status_template">';
        $html .= $this->_getStatusTemplateHtml();
        $html .= '</div>';

        $html .= $this->getLayout()->createBlock(
            'Magento\Backend\Block\Widget\Button'
        )->setData(
            ['label' => 'Force Import Now', 'id' => 'mb-sinch-import-button',
                'class' => 'mb-start-button', 'style' => 'margin-top:30px']
        )->toHtml();

        $lastImportData   = $this->sinch->getDataOfLatestImport();
        $lastImportStatus = is_array($lastImportData) ? $lastImportData['global_status_import'] : 'None';

        $html .= '<div id="sinchimport_current_status_message">';
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
        elseif ($lastImportStatus == 'None') {
            $html .= '<p>No import has run yet</p>';
        }
        $html .= '</div>';

        return $html;
    }

    protected function _appendJs(): string
    {
        $completeIcon = $this->getViewFileUrl(
            'SITC_Sinchimport::images/import_complete.gif'
        );
        $runningIcon = $this->getViewFileUrl(
            'SITC_Sinchimport::images/ajax_running.gif'
        );

        $postUrl = $this->getUrl('sinchimport/ajax');
        $postStockPriceUrl = $this->getUrl('sinchimport/ajax/stockPrice');
        $postCustomerGroupsStockPriceUrl = $this->getUrl('sinchimport/ajax/customergroupsPrice');
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
                this.postCustomerGroupsPriceUrl = '" . $postCustomerGroupsStockPriceUrl . "';
                this.indexingUrl = '" . $indexingUrl . "';
                this.postUrlUpd = '" . $postUrlUpd . "';
                this.failureUrl = document.URL;
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
                if($('mb-sinch-customer-groups-price-import-button')) {
                    Event.observe($('mb-sinch-customer-groups-price-import-button'), 'click', this.beforeCustomerGroupsPriceImport.bind(this));
                }
                if($('mb-sinch-indexing-data-button')) {
                    Event.observe($('mb-sinch-indexing-data-button'), 'click', this.beforeIndexing.bind(this));
                }
            },

            beforeFullImport: function () {
                this.setFullImportRunningIcon();
                //Hide the info about the previous import
                curr_status_div = document.getElementById('sinchimport_current_status_message');
                curr_status_div.style.display = 'none';
                //Show the status template
                status_div = document.getElementById('sinchimport_status_template');
                status_div.style.display = '';
                this.startSinchImport(this.postUrl);
            },

            setFullImportRunningIcon: function () {
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
             
            beforeCustomerGroupsPriceImport: function () {
                this.setCustomerGroupsPriceRunningIcon();
                status_div = document.getElementById('sinchimport_customer_groups_price_status_template');
                curr_status_div = document.getElementById('sinchimport_customer_groups_price_current_status_message');
                curr_status_div.style.display = 'none';
                status_div.style.display = '';
                this.startSinchImport(this.postCustomerGroupsPriceUrl);
            },
            
            setCustomerGroupsPriceRunningIcon: function () {
                runningIcon='<img src=\"" . $runningIcon . "\"/>';
                document.getElementById('sinchimport_customer_groups_price_start_import').innerHTML=runningIcon;
                document.getElementById('sinchimport_customer_groups_price_upload_files').innerHTML=runningIcon;
                document.getElementById('sinchimport_customer_groups_price_parse_products').innerHTML=runningIcon;
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

    protected function _appendCss(): string
    {
        return '';
    }

    protected function _getStatusTemplateHtml(): string
    {
        return "";
    }
}
