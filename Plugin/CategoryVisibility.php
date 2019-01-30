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
    //Stores the accountID
    private $accountID;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Customer\Model\Session $customerSession
    )
    {
        $this->resourceConn = $resourceConn;
        $this->logger = $logger;
        $this->moduleManager = $moduleManager;
        $this->customerSession = $customerSession;
        $this->catVisTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupCategories::MAPPING_TABLE);
    }

    public function aroundGetDisplayMode(\Magento\Catalog\Model\Category $subject, $proceed)
    {
        //THIS FUNCTION RUNS AFTER DEPERSONALISE (so customer session is NOT available)
        //Valid display modes are Category::DM_PRODUCT, Category::DM_PAGE, and Category::DM_MIXED or "" if the value has never been modified (i.e. DM_PRODUCT)
        if (!$this->isCategoryVisible($subject)) {
            return \Magento\Catalog\Model\Category::DM_PAGE; //Display static blocks only (no products)
        }

        return $proceed();
    }

    public function aroundGetIncludeInMenu(\Magento\Catalog\Model\Category $subject, $proceed)
    {
        //Rarely (if ever) called (at least with Infortis_UltraMegamenu)
        if (!$this->isCategoryVisible($subject)) {
            $this->logger->info("Not including category in menu");
            return 0;
        }
        return $proceed();
    }

    public function aroundGetIsActive(\Magento\Catalog\Model\Category $subject, $proceed)
    {
        //Save the account id in this function (which runs before Magento's stupid as shit depersonalise)
        if (!$this->customerSession->isLoggedIn()) {
            $this->accountID = false;
        } else {
            $this->accountID = $this->customerSession->getCustomer()->getAccountId();
        }

        //Just 404s the category, so setting display mode to DM_PAGE seems like a more reasonable compromise for now
        //if (!$this->isCategoryVisible($subject)) {
        //    return 0;
        //}
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
        if (!$this->moduleManager->isEnabled("Tigren_CompanyAccount") ||
            !$this->resourceConn->getConnection()->isTableExists($this->catVisTable)
        ) {
            //We don't affect category visibility if the company account plugin is missing/disabled
            //or if the category visibility table doesn't exist
            $this->logger->info("Tigren_CompanyAccount is disabled or {$this->catVisTable} doesn't exist");
            return true;
        }

        $catId = $category->getStoreCategoryId();
        $accountIds = $this->resourceConn->getConnection()->fetchCol(
            "SELECT account_id FROM {$this->catVisTable} WHERE category_id = :category_id",
            [":category_id" => $catId]
        );

        if(empty($accountIds)){
            //If the query returns no account id's, then this category is public
            return true;
        }

        if($this->accountID !== false && in_array($this->accountID, $accountIds)){
            //Customer is logged in and their account can see this category
            $this->logger->info("Category {$catId} is private, but account id {$this->accountID} can view it");
            return true;
        }

        $this->logger->info("Category {$catId} is private and account id {$this->accountID} doesn't have permission to view it");
        return false;
    }
}