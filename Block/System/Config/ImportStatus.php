<?php
namespace SITC\Sinchimport\Block\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Class ImportStatus
 * @package SITC\Sinchimport\Block\System\Config
 * @SuppressWarnings('unused')
 */
class ImportStatus extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        return '<div id="sinchimport-status" data-bind="scope:\'import_status\'"><!-- ko template: getTemplate() --><!-- /ko --></div>
<script type="text/x-magento-init">
    {
        "#sinchimport-status": {
            "Magento_Ui/js/core/app": {
                "components": {
                    "import_status": {
                        "component": "SITC_Sinchimport/js/import_status",
                        "displayArea": "import_status",
                        "config": {
                            "template": "SITC_Sinchimport/import_status",
                            "completeIcon": "' . $this->getViewFileUrl('SITC_Sinchimport::images/import_complete.gif') . '",
                            "runningIcon": "'. $this->getViewFileUrl('SITC_Sinchimport::images/ajax_running.gif'). '",
                            "updateURL": "' . $this->getUrl('sinchimport/ajax/updateStatus') . '"
                        }
                    }
                }
            }
        }
    }
</script>';
    }
}
