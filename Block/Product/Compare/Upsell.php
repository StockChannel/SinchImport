<?php

namespace SITC\Sinchimport\Block\Product\Compare;

use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Checkout\Model\ResourceModel\Cart;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager;
use Magento\Framework\Phrase;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Model\ResourceModel\Product\Compare\Item\RelatedCompareCollection;
use SITC\Sinchimport\Model\ResourceModel\Product\Compare\Item\RelatedCompareCollectionFactory;

class Upsell extends \Magento\Catalog\Block\Product\ProductList\Upsell
{
    /** Compare Products comparable attributes cache */
    protected ?array $_attributes = null;

    /** @var ?RelatedCompareCollection $items */
    protected ?RelatedCompareCollection $items = null;

    public function __construct(
        Context $context,
        Cart $checkoutCart,
        Visibility $catalogProductVisibility,
        Session $checkoutSession,
        Manager $moduleManager,
        private readonly RelatedCompareCollectionFactory $itemCollectionFactory,
        private readonly Data $helper,
        array $data = []
    ) {
        parent::__construct($context, $checkoutCart, $catalogProductVisibility, $checkoutSession, $moduleManager, $data);
    }

    // maybe override getItems to add the product itself?

    /**
     * Retrieve Product Compare Attributes
     *
     * @return array
     * @throws NoSuchEntityException
     */
    public function getAttributes(): array
    {
        if (empty($this->_attributes)) {
            $this->_attributes = $this->getItems()->getComparableAttributes();
        }
        return $this->_attributes;
    }

    /**
     * Retrieve Product Attribute Value
     *
     * @param Product $product
     * @param Attribute $attribute
     * @return Phrase|string
     */
    public function getProductAttributeValue(Product $product, Attribute $attribute): Phrase|string
    {
        if (!$product->hasData($attribute->getAttributeCode())) {
            return __('N/A');
        }

        if ($attribute->getSourceModel() || in_array(
                $attribute->getFrontendInput(),
                ['select', 'boolean', 'multiselect']
            )
        ) {
            $value = $attribute->getFrontend()->getValue($product);
        } else {
            $value = $product->getData($attribute->getAttributeCode());
        }
        if (is_array($value)) {
            return __('N/A');
        }
        return (string)$value == '' ? __('No') : $value;
    }

    /**
     * Check if any of the products has a value set for the attribute
     *
     * @param Attribute $attribute
     * @return bool
     * @throws NoSuchEntityException
     */
    public function hasAttributeValueForProducts(Attribute $attribute): bool
    {
        foreach ($this->getItems() as $item) {
            if ($item->hasData($attribute->getAttributeCode())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieve Product Compare items collection
     *
     * @return RelatedCompareCollection
     * @throws NoSuchEntityException
     */
    public function getItems(): RelatedCompareCollection
    {
        if ($this->items === null) {
            $this->_compareProduct->setAllowUsedFlat(false);

            /** @var RelatedCompareCollection $_items */
            $this->items = $this->itemCollectionFactory->create();
            $this->items->useProductItem()->setStoreId($this->_storeManager->getStore()->getId());

            $upsellIds = $this->getProduct()->getUpSellProductIds();
            $numProducts = ((int)$this->helper->getStoreConfig('sinchimport/related_products/upsell_compare_num_products') ?? 6) - 1;
            if (count($upsellIds) > $numProducts) {
                // Trim the set to the max number
                $upsellIds = array_slice($upsellIds, 0, $numProducts);
            }
            $this->items->setIncludedIds($this->getProduct()->getId(), $upsellIds);

            $this->items->addAttributeToSelect(
                $this->_catalogConfig->getProductAttributes()
            )->loadComparableAttributes()->addMinimalPrice()->addTaxPercents()->setVisibility(
                $this->_catalogProductVisibility->getVisibleInSiteIds()
            );
        }

        return $this->items;
    }

    public function isPrimaryProduct(Product $product): bool
    {
        return $this->getProduct()->getId() == $product->getId();
    }
}