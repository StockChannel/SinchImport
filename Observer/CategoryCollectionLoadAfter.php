<?php
namespace SITC\Sinchimport\Observer;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Model\Import\AccountGroupCategories;

class CategoryCollectionLoadAfter implements ObserverInterface
{
    private $resourceConn;
    private $helper;

    /** 
     * Holds the table name for the visibility mapping
     * @var string
     */
    private $catVisTable;

    public function __construct(
        ResourceConnection $resourceConn,
        Data $helper
    ) {
        $this->resourceConn = $resourceConn;
        $this->helper = $helper;
        $this->catVisTable = $this->resourceConn->getTableName(AccountGroupCategories::MAPPING_TABLE);
    }


    public function execute(Observer $observer)
    {
        if(!$this->helper->isCategoryVisibilityEnabled()){
            return; //No filtering if the feature isn't enabled
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();
        if($account_group_id === false) {
            return; //this is a guest (don't filter)
        }

        /** @var Collection $categoryCollection */
        $filteredCategoryCollection = $observer->getCategoryCollection();
        $categoryCollection = clone $filteredCategoryCollection;
        $filteredCategoryCollection->removeAllItems();

        //Categories explicitly made visible to this user
        $visible_cats = $this->resourceConn->getConnection()->fetchCol(
            "SELECT category_id FROM {$this->catVisTable} WHERE account_group_id = :account_group_id",
            [":account_group_id" => $account_group_id]
        );
        $noRestrict = empty($visible_cats);

        /** @var Category $category */
        foreach ($categoryCollection as $category) {
            $sinch_cat_id = $category->getStoreCategoryId();

            //If the category is explicitly visible to this account, add it back to the collection
            //Also allow seeing all categories if no restrictions exist for this account
            if (in_array($sinch_cat_id, $visible_cats) || $noRestrict) {
                $filteredCategoryCollection->addItem($category);
            }
        }
    }
}
