<?php
namespace SITC\Sinchimport\Observer;

class CategoryCollectionLoadAfter implements \Magento\Framework\Event\ObserverInterface
{
    private $resourceConn;
    private $registry;
    private $helper;

    /** 
     * Holds the table name for the visibility mapping
     * @var string
     */
    private $catVisTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Framework\Registry $registry,
        \SITC\Sinchimport\Helper\Data $helper
    ) {
        $this->resourceConn = $resourceConn;
        $this->registry = $registry;
        $this->helper = $helper;
        $this->catVisTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupCategories::MAPPING_TABLE);
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if(!$this->helper->isCategoryVisibilityEnabled()){
            return; //No filtering if the feature isn't enabled
        }

        //the DontDepersonaliseAccount interceptor saves account group id prior to depersonalise
        $account_group_id = $this->registry->registry('sitc_account_group_id');

        if($this->helper->getStoreConfig('sinchimport/category_visibility/private_visible_to_guest') && $account_group_id === false) {
            return; //private_visible_to_guest is enabled and this is a guest (don't filter)
        }

        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $categoryCollection */
        $filteredCategoryCollection = $observer->getCategoryCollection();
        $categoryCollection = clone $filteredCategoryCollection;
        $filteredCategoryCollection->removeAllItems();

        //Categories explicitly made visible to this user
        $explicit_visible_cats = $this->resourceConn->getConnection()->fetchCol(
            "SELECT category_id FROM {$this->catVisTable} WHERE account_group_id = :account_group_id",
            [":account_group_id" => $account_group_id]
        );
        //Categories hidden in general (not necessarily from this user)
        $default_hidden_cats = $this->resourceConn->getConnection()->fetchCol(
            "SELECT DISTINCT category_id FROM {$this->catVisTable}"
        );


        /** @var \Magento\Catalog\Model\Category $category */
        foreach ($categoryCollection as $category) {
            $sinch_cat_id = $category->getStoreCategoryId();

            //If the category is not hidden in general or is explicitly visible to this account, add it back to the collection
            if (!in_array($sinch_cat_id, $default_hidden_cats) || in_array($sinch_cat_id, $explicit_visible_cats)) {
                $filteredCategoryCollection->addItem($category);
            }
        }
    }
}