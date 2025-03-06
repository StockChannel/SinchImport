<?php

namespace SITC\Sinchimport\Plugin;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\AbstractAction;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\RequestInterface;
use SITC\Sinchimport\Helper\Data;

/**
 * Plugin on \Magento\Framework\App\Action\AbstractAction, adding account group to the HTTP context
 */
class VaryContext {
    const CONTEXT_DEPERSONALIZED = 'CONTEXT_HAS_DEPERSONALIZED';
    const CONTEXT_ACCOUNT_GROUP = 'CONTEXT_SITC_ACC_GRP';
    const DEFAULT_ACCOUNT_GROUP = false;

    private $customerSession;
    private $httpContext;
    private $helper;

    public function __construct(
        Session $customerSession,
        Context $httpContext,
        Data $helper
    ) {
        $this->customerSession = $customerSession;
        $this->httpContext = $httpContext;
        $this->helper = $helper;
    }

    /**
     * Set account group in the HTTP context
     *
     * @param AbstractAction $subject
     * @param RequestInterface $request
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeDispatch(AbstractAction $subject, RequestInterface $request)
    {
        $account_group_id = static::DEFAULT_ACCOUNT_GROUP;
        if ($this->helper->isModuleEnabled('Tigren_CompanyAccount') && $this->customerSession->isLoggedIn()) {
            $attr = $this->customerSession->getCustomerData()->getCustomAttribute('account_id');
            if(!empty($attr)){
                $account_group_id = $this->helper->getAccountGroupForAccount($attr->getValue());
            }
        }

        $this->httpContext->setValue(
            static::CONTEXT_ACCOUNT_GROUP,
            $account_group_id,
            static::DEFAULT_ACCOUNT_GROUP
        );
        $this->httpContext->setValue(
            static::CONTEXT_DEPERSONALIZED,
            true,
            false
        );
    }
}
