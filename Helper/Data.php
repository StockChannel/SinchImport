<?php

namespace SITC\Sinchimport\Helper;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Data extends AbstractHelper
{
    private $resourceConn;
    private $customerSession;

    private $accountTable;
    private $_storeManager;

    public function __construct(
        Context $context,
        ResourceConnection $resourceConn,
        Session $customerSession,
        StoreManagerInterface $storeManager
    )
    {
        parent::__construct($context);
        $this->resourceConn = $resourceConn;
        $this->customerSession = $customerSession;
        $this->_storeManager = $storeManager;
        $this->accountTable = $this->resourceConn->getTableName('tigren_comaccount_account');
    }

    public function isCategoryVisibilityEnabled()
    {
        return $this->isModuleEnabled("Tigren_CompanyAccount") &&
            $this->getStoreConfig('sinchimport/category_visibility/enable') == 1;
    }

    public function isModuleEnabled($moduleName)
    {
        return $this->_moduleManager->isEnabled($moduleName);
    }

    public function getStoreConfig($configPath)
    {
        return $this->scopeConfig->getValue(
            $configPath,
            ScopeInterface::SCOPE_STORE
        );
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
    public function scheduleImport($importType)
    {
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
     * @insertCategoryIdForFinder
     */
    public function insertCategoryIdForFinder()
    {
        $tbl_store = $this->resourceConn->getTableName('store');
        $tbl_cat = $this->resourceConn->getTableName('catalog_category_product');
        $this->resourceConn->getConnection()->query(" 
                INSERT INTO " . $this->resourceConn->getTableName('catalog_category_product_index') . " (
                category_id, product_id, position, is_parent, store_id, visibility) (
                    SELECT a.category_id, a.product_id, a.position, 1, b.store_id, 4
                    FROM " . $tbl_cat . " a
                        JOIN " . $tbl_store . " b )
                ON DUPLICATE KEY UPDATE visibility = 4"
        );

        foreach ($this->_storeManager->getStores() as $store) {
            $table = $this->resourceConn->getTableName('catalog_category_product_index_store' . $store->getId());
            if ($this->resourceConn->getConnection()->isTableExists($table)) {
                $this->resourceConn->getConnection()->query(" 
                  INSERT INTO " . $table . " (category_id, product_id, position, is_parent, store_id, visibility) (
                      SELECT  a.category_id, a.product_id, a.position, 1, b.store_id, 4
                      FROM " . $tbl_cat . " a
                        JOIN " . $tbl_store . " b )
                  ON DUPLICATE KEY UPDATE visibility = 4"
                );
            }
        }
    }
}
