<?php
namespace SITC\Sinchimport\Helper;

class CustomPricing extends \Magento\Framework\App\Helper\AbstractHelper {
    /** @var \Magento\Framework\App\ResourceConnection $resourceConn */
    private $resourceConn;
    /** @var \Magento\Catalog\Model\ProductFactory\Proxy $productFactory */
    private $productFactory;

    /** @var string $cgpTable Customer Group price table name */
    private $cgpTable;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Magento\Catalog\Model\ProductFactory\Proxy $productFactory
    ){
        parent::__construct($context);
        $this->resourceConn = $resourceConn;
        $this->productFactory = $productFactory;

        $this->cgpTable = $this->resourceConn->getTableName('sinch_customer_group_price');
    }

    /**
     * Get Price rule matching a given account group and product
     * 
     * @param int $accountGroup Account Group ID
     * @param \Magento\Catalog\Model\Product $product Product
     * @return float|null
     */
    public function getAccountGroupPrice($accountGroup, $product)
    {
        // $adjustedPrice = 0.0;

        // if($product->getTypeId() === 'bundle') {
        //     $childIds = $product->getTypeInstance(true)->getChildrenIds($product->getId(), true); //True indicates to get "required" children
        //     foreach ($childIds as $id) {
        //         $childProd = $this->productFactory->create();
        //         $childProd->load($id);
        //         $res = $this->getAccountGroupPrice($accountGroup, $childProd);
        //         $adjustedPrice += !empty($res) ? $res : $child->getFinalPrice();
        //     }
        //     return $adjustedPrice;
        // }

        // if($product->getTypeId() === 'grouped') {
        //     $usedProds = $product->getTypeInstance(true)->getAssociatedProducts($product);
        //     foreach ($usedProds as $child) {
        //         if ($child->getId() != $product->getId()) {
        //             $res = $this->getAccountGroupPrice($accountGroup, $child);
        //             $adjustedPrice += !empty($res) ? $res : $child->getFinalPrice();
        //         }
        //     }
        //     return $adjustedPrice;
        // }

        $select = $this->resourceConn->getConnection()->select()
            ->from($this->cgpTable, ['customer_group_price'])
            ->where('group_id = ?', $accountGroup)
            ->where('product_id = ?', $product->getId())
            ->where('price_type_id = ?', 1)
            ->where('customer_group_price > 0');
        
        return $this->resourceConn->getConnection()->fetchOne($select);
    }

    /**
     * Get Price rules matching a given account group and set of products
     * 
     * @param int $accountGroup Account Group ID
     * @param int[] $products Product IDs
     * @return array
     */
    public function getAccountGroupPrices($accountGroup, $products)
    {
        $select = $this->resourceConn->getConnection()->select()
            ->from($this->cgpTable, ['product_id', 'customer_group_price'])
            ->where('group_id = ?', $accountGroup)
            ->where('product_id IN (?)', $products)
            ->where('price_type_id = ?', 1)
            ->where('customer_group_price > 0');
        
        return $this->resourceConn->getConnection()->fetchAll($select);
    }

    /**
     * Set the price of a product, without triggering Magento to show any discount info
     * 
     * @param \Magento\Catalog\Model\Product $product The product to set price for
     * @param float $price The new price
     * 
     * @return void
     */
    public function setProductPrice($product, $price)
    {
        $product->setPrice($price);
        $product->setMinPrice($price);
        $product->setMinimalPrice($price);
        $product->setMaxPrice($price);
        $product->setTierPrice($price);
        $product->setFinalPrice($price);
    }
}