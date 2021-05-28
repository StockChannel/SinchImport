<?php

namespace SITC\Sinchimport\Block\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use SITC\Sinchimport\Model\Sinch;

class Indexingbutton extends Field
{
    protected Sinch $sinch;

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
        return $this->getLayout()
            ->createBlock('Magento\Backend\Block\Widget\Button')
            ->setData([
                'label' => 'Indexing data',
                'id' => 'mb-sinch-indexing-data-button',
                'class' => 'mb-indexing-button',
                'style' => 'margin-top:30px'
            ])->toHtml();
    }

}
