<?php

namespace SITC\Sinchimport\Controller\Product;

use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use SITC\Sinchimport\Helper\Data;

class BySKU implements ActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Data $helper,
        private readonly ProductRepository $productRepository,
        private readonly RedirectFactory $redirectFactory,
    ) {}

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $sku = $this->request->getParam('sku');
        if (empty($sku)) {
            return $this->redirectTo404();
        }
        try {
            $product = $this->productRepository->get($sku);
            return $this->redirectFactory->create()->setUrl($product->getProductUrl());
        } catch (NoSuchEntityException) {}
        return $this->redirectTo404();
    }

    function redirectTo404(): Redirect
    {
        return $this->redirectFactory->create()->setPath(
            $this->helper->getStoreConfig('web/default/no_route') ?? 'cms/noroute/index'
        );
    }
}