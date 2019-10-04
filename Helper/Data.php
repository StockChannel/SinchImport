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
            //TODO: Change this to use customer groups once account_group_id actually refers to Magento customer groups
            //For now, just query the data we need directly from SQL
            $account_group_id = $this->resourceConn->getConnection()->fetchOne(
                "SELECT account_group_id FROM {$this->accountTable} WHERE account_id = :account_id",
                [":account_id" => $account_id]
            );
        }
        return $account_group_id;
    }

    /**
     * Schedule an import for execution as soon as possible
     * @param string $importType The type of import, one of "PRICE STOCK" and "FULL"
     * @return void
     */
    public function scheduleImport($importType) {
        $importStatus = $this->resourceConn->getTableName('sinch_import_status');
        //Clear the status table so the admin panel doesn't immediately mark it as complete
        $this->resourceConn->getConnection()->query(
            "DELETE FROM {$importStatus}"
        );

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
}
