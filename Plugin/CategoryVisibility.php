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
     * @var \SITC\Sinchimport\Helper\Data
     */
    private $helper;

    /**
     * The table name of the category visibility mapping
     *
     * @var string
     */
    private $catVisTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger,
        \SITC\Sinchimport\Helper\Data $helper
    ){
        $this->resourceConn = $resourceConn;
        $this->logger = $logger;
        $this->helper = $helper;
        $this->catVisTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupCategories::MAPPING_TABLE);
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
        if (!$this->helper->isCategoryVisibilityEnabled()) {
            return true;
        }

        $catId = $category->getStoreCategoryId();
        $account_group_id = $this->helper->getCurrentAccountGroupId();

        if($account_group_id === false){
            //Customer is not logged in
            return true;
        }

        $groupRuleCount = $this->resourceConn->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM {$this->catVisTable} WHERE account_group_id = :account_group_id",
            [":account_group_id" => $account_group_id]
        );

        if($groupRuleCount == 0) {
            //This account group has no category visibility rules, everything is public
            return true;
        }

        $accountGroupIds = $this->resourceConn->getConnection()->fetchCol(
            "SELECT account_group_id FROM {$this->catVisTable} WHERE category_id = :category_id",
            [":category_id" => $catId]
        );

        if(in_array($account_group_id, $accountGroupIds)){
            //Customer is logged in and their account can see this category
            return true;
        }

        //Private and account (if any) has no perms to view
        return false;
    }
}