<?php
namespace SITC\Sinchimport\Block\Product;

/**
 * @api
 * @method string getWidth()
 * @method string getHeight()
 * @method string getLabel()
 * @method mixed getResizedImageWidth()
 * @method mixed getResizedImageHeight()
 * @method float getRatio()
 * @method string getCustomAttributes()
 * @since 100.0.2
 */
class Image extends \Magento\Catalog\Block\Product\Image
{
    /**
     * @deprecated Property isn't used
     * @var \Magento\Catalog\Helper\Image
     */
    protected $imageHelper;

    /**
     * @deprecated Property isn't used
     * @var \Magento\Catalog\Model\Product
     */
    protected $product;

    /**
     * @deprecated Property isn't used
     * @var array
     */
    protected $attributes = [];

    /**
     * @var \Magento\Checkout\Model\Cart
     */
    protected $cart;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;

    /**
     * @var \Magento\Catalog\Helper\ImageFactory
     */
    protected $imageHelperFactory;

    /**
     * Image constructor.
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Checkout\Model\Cart $cart
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param \Magento\Catalog\Helper\ImageFactory $imageHelperFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Helper\ImageFactory $imageHelperFactory,
        array $data = []
    ) {
        if (isset($data['template'])) {
            $this->setTemplate($data['template']);
            unset($data['template']);
        }
        $this->cart = $cart;
        $this->productFactory = $productFactory;
        $this->imageHelperFactory = $imageHelperFactory;
        parent::__construct($context, $data);
    }


    /**
     * @return string
     */
    public function getImageUrl()
    {
        $items = $this->cart->getQuote()->getAllItems();
        $image = '';
        foreach ($items as $item) {
            $product = $this->productFactory->create()->load($item['product_id']);
            $imageUrl = $this->imageHelperFactory->create()
                ->init($product, 'product_thumbnail_image')->getUrl();
            if (substr($this->getImageProduct($item['product_id']), 0, 4) == 'http') {
                $image = $this->getImageProduct($item['product_id']);
            } else {
                $image = $imageUrl;
            }
        }
        return $image;
    }

    /**
     * @param $productId
     * @return string
     */
    public function getImageProduct($productId)
    {
        $product = $this->productFactory->create()->load($productId);
        $image = '';
        foreach ($product as $_product) {
            $image = $_product['image'];
        }
        return $image;
    }

    /**
     * Set path to template used for generating block's output.
     *
     * @param string $template
     * @return $this
     */
//    public function setTemplate($template)
//    {
//        $template = 'SITC_Sinchimport::product/image_with_borders.phtml';
//        $this->_template = $template;
//        return $this;
//    }
}