<?php
namespace SITC\Sinchimport\Observer;

class CategoryCollectionLoadAfter implements \Magento\Framework\Event\ObserverInterface
{
    private $resourceConn;
    private $registry;

    /** 
     * Holds the table name for the visibility mapping
     * @var string
     */
    private $catVisTable;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Framework\Registry $registry
    ) {
        $this->resourceConn = $resourceConn;
        $this->registry = $registry;
        $this->catVisTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupCategories::MAPPING_TABLE);
    }


    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $categoryCollection */
        $filteredCategoryCollection = $observer->getCategoryCollection();
        $categoryCollection = clone $filteredCategoryCollection;
        $filteredCategoryCollection->removeAllItems();


        //the DontDepersonaliseAccount interceptor saves account id prior to depersonalise
        $account_id = $this->registry->registry('sitc_account_id');

        //Categories explicitly made visible to this user
        $explicit_visible_cats = $this->resourceConn->getConnection()->fetchCol(
            "SELECT category_id FROM {$this->catVisTable} WHERE account_id = :account_id",
            [":account_id" => $account_id]
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