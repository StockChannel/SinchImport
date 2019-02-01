<?php
namespace SITC\Sinchimport\Plugin;

/**
 * This class exists SOLELY for the purpose of preventing Magento from depersonalising the customerSession before we can get the account info from it
 */
class DontDepersonaliseAccount {

    private $customerSession;
    private $registry;

    public function __construct(
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Registry $registry
    ) {
        $this->customerSession = $customerSession;
        $this->registry = $registry;
    }

    //Magento hooks afterGenerateXml to depersonalise, so running on before should guarantee we can get account info
    public function beforeGenerateXml(\Magento\Framework\View\LayoutInterface $subject)
    {
        $account_id = false;
        if ($this->customerSession->isLoggedIn()) {
            $account_id = $this->customerSession->getCustomer()->getAccountId();
        }
        $this->registry->register('sitc_account_id', $account_id, true);
    }
}