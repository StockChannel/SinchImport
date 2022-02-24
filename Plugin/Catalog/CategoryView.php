<?php

namespace SITC\Sinchimport\Plugin\Catalog;

use Magento\Catalog\Block\Product\Image;
use Magento\Catalog\Model\ProductRepository;
use SITC\Sinchimport\Helper\Badges;


class CategoryView
{

    private Badges $badgeHelper;
    private ProductRepository $productRepository;
    private $productCollection;


    /**
     * @param Badges $badgeHelper
     * @param ProductRepository $productRepository
     */
    public function __construct(Badges $badgeHelper, ProductRepository $productRepository)
    {
        $this->badgeHelper = $badgeHelper;
        $this->productRepository = $productRepository;
    }

    public function afterToHtml(Image $subject, $result)
    {
        $badgeProducts = $this->productCollection;

        foreach (array_keys(Badges::BADGE_TYPES) as $badgeType) {
            $badgeTypeProductId = $badgeProducts[$badgeType] ?? -1;
            try {
                $product = $this->productRepository->getById($badgeTypeProductId);
            } catch (\Exception $e) {
                continue;
            }

            $badgeImageUrl = $this->badgeHelper->getBadgeImageUrl($badgeType) ?? '';
            $badgeTitle = $this->badgeHelper->getFormattedBadgeTitle($badgeType);
            $productName = $product->getName();

            if ($productName == $subject->getLabel()) {
                $html = "<div class='badge-1 badge-custom'>
                    <img src='$badgeImageUrl'  alt='$badgeTitle'/>
                </div>";

                return $result . $html;
            }
        }

        return $result;
    }

    public function setProductCollection($productCollection)
    {
        $this->productCollection = $productCollection;
    }

}