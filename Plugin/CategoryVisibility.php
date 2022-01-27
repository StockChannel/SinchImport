<?php
namespace SITC\Sinchimport\Plugin;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Import\AccountGroupCategories;

class CategoryVisibility {
    /**
     * @var ResourceConnection
     */
    private $resourceConn;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Data
     */
    private $helper;

    /**
     * The table name of the category visibility mapping
     *
     * @var string
     */
    private $catVisTable;

    public function __construct(
        ResourceConnection $resourceConn,
        Logger $logger,
        Data $helper
    ){
        $this->resourceConn = $resourceConn;
        $this->logger = $logger;
        $this->helper = $helper;
        $this->catVisTable = $this->resourceConn->getTableName(AccountGroupCategories::MAPPING_TABLE);
    }

    public function aroundGetIsActive(Category $subject, $proceed)
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
     * @param Category $category
     * @return bool
     */
    private function isCategoryVisible(Category $category)
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
