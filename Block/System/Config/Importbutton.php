<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use SITC\Sinchimport\Model\Sinch;

class Importbutton extends Field
{
    protected $sinch;

    /**
     * @param Context $context
     * @param array                                   $data
     */
    public function __construct(
        Context $context,
        Sinch $sinch,
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

        $html .= $this->getLayout()
            ->createBlock('Magento\Backend\Block\Widget\Button')
            ->setData([
                'label' => 'Force Import Now',
                'id' => 'mb-sinch-import-button',
                'class' => 'mb-start-button',
                'style' => 'margin-top:30px'
            ])
            ->toHtml();

        $lastImportData   = $this->sinch->getDataOfLatestImport();

        $html .= '<div id="sinchimport_current_status_message">';
        switch (is_array($lastImportData) ? $lastImportData['global_status_import'] : 'None') {
            case 'Failed':
                $html .= "<p class=\"sinch-error\">The import has failed. Last step was \"{$lastImportData['detail_status_import']}\"<br>";
                $html .= 'Error message: <pre>' . $lastImportData['error_report_message'] . '</pre></p>';
                break;
            case 'Successful':
                $html .= "<p class=\"sinch-success\">{$lastImportData['number_of_products']} products imported succesfully!</p>";
                break;
            case 'Run':
                $html .= '<p>Import is running now</p>';
                break;
            case 'None':
                $html .= '<p>No import has run yet</p>';
                break;
        }
        $html .= '</div>';

        return $html;
    }

    protected function _appendJs(): string
    {
        $postUrl = $this->getUrl('sinchimport/ajax');
        $postStockPriceUrl = $this->getUrl('sinchimport/ajax/stockPrice');
        $postCustomerGroupsStockPriceUrl = $this->getUrl('sinchimport/ajax/customergroupsPrice');
        $indexingUrl = $this->getUrl('sinchimport/ajax/indexingData');

        return "<script>
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
                this.failureUrl = document.URL;

                elem = 'checkoutSteps';
                clickableEntity = '.head';

                // overwrite Accordion class method
                var headers = $$('#' + elem + ' .section ' + clickableEntity);
                headers.each(function(header) {
                    Event.observe(header,'click',this.sectionClicked.bindAsEventListener(this));
                }.bind(this));

                Event.observe($('mb-sinch-import-button'), 'click', this.beforeFullImport.bind(this));
                
                let stockPriceBtn = $('mb-sinch-stock-price-import-button');
                if (stockPriceBtn) {
                    Event.observe(stockPriceBtn, 'click', this.beforeStockPriceImport.bind(this));
                }
                
                let indexingBtn = $('mb-sinch-indexing-data-button');
                if (indexingBtn) {
                    Event.observe(indexingBtn, 'click', this.beforeIndexing.bind(this));
                }
                
                let cgpBtn = $('mb-sinch-customer-groups-price-import-button');
                if (cgpBtn) {
                    Event.observe(cgpBtn, 'click', this.beforeCustomerGroupsPriceImport.bind(this));
                }
            },

            beforeFullImport: function () {
                //Hide the info about the previous import
                let curr_status_div = document.getElementById('sinchimport_current_status_message');
                curr_status_div.style.display = 'none';
                this.startSinchImport(this.postUrl);
            },

            beforeStockPriceImport: function () {
                let curr_status_div = document.getElementById('sinchimport_stock_price_current_status_message');
                curr_status_div.style.display = 'none';
                this.startSinchImport(this.postStockPriceUrl);
            },
             
            beforeCustomerGroupsPriceImport: function () {
                let curr_status_div = document.getElementById('sinchimport_customer_groups_price_current_status_message');
                curr_status_div.style.display = 'none';
                this.startSinchImport(this.postCustomerGroupsPriceUrl);
            },
            
            beforeIndexing: function () {
                this.startSinchImport(this.indexingUrl);
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
                location.href = this.failureUrl;
            },
        }

        sinchImport = new Sinch();
    });
</script>";
    }

    protected function _appendCss(): string
    {
        return '';
    }
}
