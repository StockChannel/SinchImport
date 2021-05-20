<?php
namespace SITC\Sinchimport\Block\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ImportStatus extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        return '<table class="data-table history import-history" data-role="sinchimport-status"></table>
<script type="text/x-magento-init">
    {
        "[data-role=sinchimport-status]": {
            "SITC_Sinchimport/js/import_status": {
                "completeIcon": "' . $this->getViewFileUrl('SITC_Sinchimport::images/import_complete.gif') . '",
                "runningIcon": "'. $this->getViewFileUrl('SITC_Sinchimport::images/ajax_running.gif'). '",
                "updateURL": "' . $this->getUrl('sinchimport/ajax/updateStatus') . '"
            }
        }
    }
</script>';
    }
}