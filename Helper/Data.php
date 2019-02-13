<?php
namespace SITC\Sinchimport\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $resourceConn;
    private $customerSession;
    private $moduleManager;

    private $accountTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->resourceConn = $resourceConn;
        $this->customerSession = $customerSession;
        $this->moduleManager = $moduleManager;
        $this->scopeConfig = $scopeConfig; //scopeConfig is defined in AbstractHelper
        $this->accountTable = $this->resourceConn->getTableName('tigren_comaccount_account');
    }

    public function getStoreConfig($configPath)
    {
        return $this->scopeConfig->getValue(
            $configPath,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    public function isModuleEnabled($moduleName)
    {
        return $this->moduleManager->isEnabled($moduleName);
    }

    public function isCategoryVisibilityEnabled()
    {
        return $this->isModuleEnabled("Tigren_CompanyAccount") &&
            $this->getStoreConfig('sinchimport/category_visibility/enable') == 1;
    }

    public function getCurrentAccountGroupId()
    {
        $account_group_id = false;
        if ($this->isCategoryVisibilityEnabled() && $this->customerSession->isLoggedIn()) {
            $account_id = $this->customerSession->getCustomer()->getAccountId();
            //TODO: Change this to use customer groups once account_group_id actually refers to Magento customer groups
            //For now, just query the data we need directly from SQL
            $account_group_id = $this->resourceConn->getConnection()->fetchOne(
                "SELECT account_group_id FROM {$this->accountTable} WHERE account_id = :account_id",
                [":account_id" => $account_id]
            );
        }
        return $account_group_id;
    }
}
