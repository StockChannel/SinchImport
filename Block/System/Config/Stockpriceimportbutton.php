<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use SITC\Sinchimport\Model\Sinch;

/**
 * Class Stockpriceimportbutton
 * @package SITC\Sinchimport\Block\System\Config
 * @SuppressWarnings('unused')
 */
class Stockpriceimportbutton extends Field
{
    protected $sinch;
    

    public function __construct(Context $context, Sinch $sinch, array $data = [])
    {
        parent::__construct($context, $data);
        $this->sinch = $sinch;
    }

    /**
     * @param AbstractElement $element
     *
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @codeCoverageIgnore
     * @throws LocalizedException
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = '';
        
        if (! $this->sinch->isFullImportHaveBeenRun()) {
            $html .= "Full import has never finished successfully";
        } else {
            $html .= $this->getLayout()
                ->createBlock('Magento\Backend\Block\Widget\Button')
                ->setData([
                    'label' => 'Force Stock & Prices Import Now',
                    'id'    => 'mb-sinch-stock-price-import-button',
                    'class' => 'mb-start-button',
                    'style' => 'margin-top:30px'
                ])
                ->toHtml();
        }
        
        $lastImportData = $this->sinch->getDataOfLatestImport();

        $html .= '<div id="sinchimport_stock_price_current_status_message"><br><br><hr/>';
        if (!empty($lastImportData) && $lastImportData['import_type'] == 'PRICE STOCK') {
            switch ($lastImportData['global_status_import']) {
                case 'Failed':
                    $html .= "<p class=\"sinch-error\">Previous import failed. Last step was \"{$lastImportData['detail_status_import']}\"<br>";
                    $html .= 'Error message: <pre>' . $lastImportData['error_report_message'] . '</pre></p>';
                    break;
                case 'Successful':
                    $html .= "<p class=\"sinch-success\">{$lastImportData['number_of_products']} products imported succesfully!</p>";
                    break;
                case 'Run':
                    $html .= '<p class="sinch-processing">Import is running now</p>';
                    break;
            }
        }
        $html .= '</div>';

        return $html;
    }
}
