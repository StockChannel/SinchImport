<?php
namespace SITC\Sinchimport\Plugin;

class CategoryVisibility {
    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConn;

    /**
     * @var \SITC\Sinchimport\Logger\Logger
     */
    private $logger;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManager;

    /**
     * @var \Magento\Customer\Model\Session
     */
    private $customerSession;

    /**
     * The table name of the category visibility mapping
     *
     * @var string
     */
    private $catVisTable;
    private $accountTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Customer\Model\Session $customerSession
    ){
        $this->resourceConn = $resourceConn;
        $this->logger = $logger;
        $this->moduleManager = $moduleManager;
        $this->customerSession = $customerSession;
        $this->catVisTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupCategories::MAPPING_TABLE);
        $this->accountTable = $this->resourceConn->getTableName('tigren_comaccount_account');
    }

    public function aroundGetIsActive(\Magento\Catalog\Model\Category $subject, $proceed)
    {
        if (!$this->isCategoryVisible($subject)) {
            //Prevents the category being visible by direct navigation (i.e. if they have the link)
            return 0;
        }
        return $proceed();
    }

    /**
     * Establishes whether a category is visible to the current user
     *
     * @param \Magento\Catalog\Model\Category $category
     * @return bool
     */
    private function isCategoryVisible(\Magento\Catalog\Model\Category $category)
    {
        if (!$this->moduleManager->isEnabled("Tigren_CompanyAccount")) {
            //We don't affect category visibility if the company account plugin is missing/disabled
            //or if the category visibility table doesn't exist
            $this->logger->info("Tigren_CompanyAccount is disabled");
            return true;
        }

        $catId = $category->getStoreCategoryId();
        $accountGroupIds = $this->resourceConn->getConnection()->fetchCol(
            "SELECT account_group_id FROM {$this->catVisTable} WHERE category_id = :category_id",
            [":category_id" => $catId]
        );

        if(empty($accountGroupIds)){
            //If the query returns no account id's, then this category is public
            return true;
        }

        $account_group_id = false;
        if ($this->customerSession->isLoggedIn()) {
            $account_id = $this->customerSession->getCustomer()->getAccountId();
            //TODO: Change this to use customer groups once account_group_id actually refers to Magento customer groups
            //For now, just query the data we need directly from SQL
            $account_group_id = $this->resourceConn->getConnection()->fetchOne(
                "SELECT account_group_id FROM {$this->accountTable} WHERE account_id = :account_id",
                [":account_id" => $account_id]
            );
        }

        if($account_group_id !== false && in_array($account_group_id, $accountGroupIds)){
            //Customer is logged in and their account can see this category
            return true;
        }

        //Private and account (if any) has no perms to view
        return false;
    }
}