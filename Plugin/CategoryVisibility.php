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

        $catId = $category->getId();
        $accountIds = $this->resourceConn->getConnection()->fetchCol(
            "SELECT account_id FROM {$this->catVisTable} WHERE category_id = :category_id",
            [":category_id" => $catId]
        );

        if (!$this->customerSession->isLoggedIn()) {
            $currentAccount = false;
        } else {
            $currentAccount = $this->customerSession->getCustomer()->getAccountId();
        }

        if(empty($accountIds)){
            //If the query returns no account id's, then this category is public
            return true;
        }

        if($currentAccount !== false && in_array($currentAccount, $accountIds)){
            //Customer is logged in and their account can see this category
            $this->logger->info("Category {$catId} is private, but account id {$currentAccount} can view it");
            return true;
        }

        $this->logger->info("Category {$catId} is private and account id {$currentAccount} doesn't have permission to view it");
        return false;
    }
}