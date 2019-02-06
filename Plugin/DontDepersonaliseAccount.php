<?php
namespace SITC\Sinchimport\Plugin;

/**
 * This class exists SOLELY for the purpose of preventing Magento from depersonalising the customerSession before we can get the account info from it
 */
class DontDepersonaliseAccount {
    const COMPANYACCOUNT_TABLE = 'tigren_comaccount_account';

    private $customerSession;
    private $registry;
    private $moduleManager;
    private $resourceConn;

    private $accountTableNameFinal;
    //This would be a const, but PHP won't allow interpolation of constants into strings
    private $accountGroupColumn = 'account_group_id';

    public function __construct(
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\App\ResourceConnection $resourceConn
    ) {
        $this->customerSession = $customerSession;
        $this->registry = $registry;
        $this->moduleManager = $moduleManager;
        $this->resourceConn = $resourceConn;
        $this->accountTableNameFinal = $this->resourceConn->getConnection()->getTableName(self::COMPANYACCOUNT_TABLE);
    }

    //Magento hooks afterGenerateXml to depersonalise, so running on before should guarantee we can get account info
    public function beforeGenerateXml(\Magento\Framework\View\LayoutInterface $subject)
    {
        $account_group_id = false;
        if ($this->isCompanyAccountAvailable() && $this->customerSession->isLoggedIn()) {
            $account_id = $this->customerSession->getCustomer()->getAccountId();
            //TODO: Change this to use customer groups once account_group_id actually refers to Magento customer groups
            //For now, just query the data we need directly from SQL
            $account_group_id = $this->resourceConn->getConnection()->fetchOne(
                "SELECT {$this->accountGroupColumn} FROM {$this->accountTableNameFinal} WHERE account_id = :account_id",
                [":account_id" => $account_id]
            );
        }
        $this->registry->register('sitc_account_group_id', $account_group_id, true);
    }

    private function isCompanyAccountAvailable()
    {
        $conn = $this->resourceConn->getConnection();
        return $this->moduleManager->isEnabled("Tigren_CompanyAccount") &&
            $conn->isTableExists($this->accountTableNameFinal) &&
            $conn->tableColumnExists($this->accountTableNameFinal, $this->accountGroupColumn);
    }
}