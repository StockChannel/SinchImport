<?php
namespace SITC\Sinchimport\Plugin;

/**
 * This class exists SOLELY for the purpose of preventing Magento from depersonalising the customerSession before we can get the account info from it
 */
class DontDepersonaliseAccount {
    private $registry;
    private $helper;

    public function __construct(
        \Magento\Framework\Registry $registry,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        $this->registry = $registry;
        $this->helper = $helper;
    }

    //Magento hooks afterGenerateXml to depersonalise, so running on before should guarantee we can get account info
    public function beforeGenerateXml(\Magento\Framework\View\LayoutInterface $subject)
    {
        $account_group_id = $this->helper->getCurrentAccountGroupId();
        $this->registry->register('sitc_account_group_id', $account_group_id, true);
    }
}