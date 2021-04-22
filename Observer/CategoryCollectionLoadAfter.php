<?php
namespace SITC\Sinchimport\Observer;

class CategoryCollectionLoadAfter implements \Magento\Framework\Event\ObserverInterface
{
    private $resourceConn;
    private $helper;

    /** 
     * Holds the table name for the visibility mapping
     * @var string
     */
    private $catVisTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        $this->resourceConn = $resourceConn;
        $this->helper = $helper;
        $this->catVisTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\AccountGroupCategories::MAPPING_TABLE);
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->helper->isCategoryVisibilityEnabled()){
            return; //No filtering if the feature isn't enabled
        }

        $account_group_id = $this->helper->getCurrentAccountGroupId();
        if($account_group_id === false) {
            return; //this is a guest (don't filter)
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $categoryCollection */
        $filteredCategoryCollection = $observer->getCategoryCollection();
        $categoryCollection = clone $filteredCategoryCollection;
        $filteredCategoryCollection->removeAllItems();

        //Categories explicitly made visible to this user
        $visible_cats = $this->resourceConn->getConnection()->fetchCol(
            "SELECT category_id FROM {$this->catVisTable} WHERE account_group_id = :account_group_id",
            [":account_group_id" => $account_group_id]
        );
        $noRestrict = empty($visible_cats);

        /** @var \Magento\Catalog\Model\Category $category */
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