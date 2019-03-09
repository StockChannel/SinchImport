<?php

namespace SITC\Sinchimport\Helper;

use Magento\Framework\App\Helper\Context;

/**
 * Class Data
 * @package SITC\Sinchimport\Helper
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var \Magento\Catalog\Model\ProductRepository
     */
    protected $_productRepository;

    /**
     * Data constructor.
     * @param Context $context
     * @param \Magento\Catalog\Model\ProductRepository $productRepository
     */
    public function __construct(
        Context $context,
        \Magento\Catalog\Model\ProductRepository $productRepository
    ){
        $this->_productRepository = $productRepository;
        parent::__construct($context);
    }

    /**
     * @param $productId
     * @return \Magento\Catalog\Api\Data\ProductInterface|mixed
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getProductById($productId) {
        return $this->_productRepository->getById($productId);
    }
}