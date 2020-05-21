<?php
namespace SITC\Sinchimport\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /** @var \Magento\Framework\App\ResourceConnection $resourceConn */
    private $resourceConn;
    /** @var \Magento\Customer\Model\Session\Proxy $customerSession */
    private $customerSession;
    /** @var \Magento\Framework\Filesystem\DirectoryList\Proxy $dir */
    private $dir;

    /** @var string $accountTable */
    private $accountTable;
    /** @var string $groupMappingTable */
    private $groupMappingTable;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Customer\Model\Session\Proxy $customerSession,
        \Magento\Framework\Filesystem\DirectoryList\Proxy $dir
    ) {
        parent::__construct($context);
        $this->resourceConn = $resourceConn;
        $this->customerSession = $customerSession;
        $this->dir = $dir;
        $this->accountTable = $this->resourceConn->getTableName('tigren_comaccount_account');
        $this->groupMappingTable = $this->resourceConn->getTableName('sinch_group_mapping');
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
        return $this->_moduleManager->isEnabled($moduleName);
    }

    public function isCategoryVisibilityEnabled()
    {
        return $this->isModuleEnabled("Tigren_CompanyAccount") &&
            $this->getStoreConfig('sinchimport/category_visibility/enable') == 1;
    }

    public function isProductVisibilityEnabled()
    {
        return $this->isModuleEnabled("Tigren_CompanyAccount") &&
            $this->getStoreConfig('sinchimport/product_visibility/enable') == 1;
    }

    public function getCurrentAccountGroupId()
    {
        $account_group_id = false;
        if ($this->isModuleEnabled('Tigren_CompanyAccount') && $this->customerSession->isLoggedIn()) {
            $account_id = $this->customerSession->getCustomer()->getAccountId();
            $account_group_id = $this->getAccountGroupForAccount($account_id);
        }
        return $account_group_id;
    }

    public function getAccountGroupForAccount($accountId)
    {
        return $this->resourceConn->getConnection()->fetchOne(
            "SELECT account_group_id FROM {$this->accountTable} WHERE account_id = :account_id",
            [":account_id" => $accountId]
        );
    }

    /**
     * Schedule an import for execution as soon as possible
     * @param string $importType The type of import, one of "PRICE STOCK" and "FULL"
     * @return void
     */
    public function scheduleImport($importType) {
        $importStatus = $this->resourceConn->getTableName('sinch_import_status');
        //Clear the status table so the admin panel doesn't immediately mark it as complete
        if($this->resourceConn->getConnection()->isTableExists($importStatus)) {
            $this->resourceConn->getConnection()->query(
                "DELETE FROM {$importStatus}"
            );
        }

        $importStatusStat = $this->resourceConn->getTableName('sinch_import_status_statistic');
        $this->resourceConn->getConnection()->query(
            "INSERT INTO {$importStatusStat} (
                start_import,
                finish_import,
                import_type,
                global_status_import,
                import_run_type,
                error_report_message
            )
            VALUES(
                NOW(),
                '0000-00-00 00:00:00',
                :import_type,
                'Scheduled',
                'MANUAL',
                ''
            )",
            [":import_type" => $importType]
        );
    }

    /**
     * Returns whether the index lock is currently held
     * (indicating a running import, or an intentional indexing pause)
     * @return bool Whether the lock is currently held
     */
    public function isIndexLockHeld()
    {
        //Manual lock indexing flag (for testing/holding the indexers for other reasons)
        if (file_exists($this->dir->getPath("var") . "/sinch_lock_indexers.flag")) {
            return true;
        }

        //Import lock
        $current_vhost = $this->scopeConfig->getValue(
            'web/unsecure/base_url',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        $is_lock_free = $this->resourceConn->getConnection()->fetchOne("SELECT IS_FREE_LOCK('sinchimport_{$current_vhost}')");
        if ($is_lock_free === '0') {
            return true;
        }
        return false;
    }

    /**
     * Returns the customer group ID for the given account group ID. 
     * Returns null if there is no corresponding group
     * @param int $accountGroupId
     * @return int|null
     */
    public function getCustomerGroupForAccountGroup($accountGroupId)
    {
        $res = $this->resourceConn->getConnection()->fetchOne(
            "SELECT magento_id FROM {$this->groupMappingTable} WHERE sinch_id = :accountGroupId",
            [':accountGroupId' => $accountGroupId]
        );
        if(!is_numeric($res)){
            return null;
        }
        return (int)$res;
    }

    public function getCustomerGroupForAccount($accountId)
    {
        $accountGroup = $this->getAccountGroupForAccount($accountId);
        if(empty($accountGroup)) {
            return null;
        }
        return $this->getCustomerGroupForAccountGroup($accountGroup);
    }
}
