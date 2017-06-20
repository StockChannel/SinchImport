<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Block\Product\View;

class Gallery
{
    /**
     * Retrieve product images in JSON format
     *
     * @return string
     */
    public function afterGetGalleryImagesJson($subject, $result)
    {
        $currentProduct = $subject->getProduct();
        if($productImage = $currentProduct->getImage()) {
            if (substr($productImage,0,4) == 'http') {
                $imagesItems[] = [
                    'thumb' => $productImage,
                    'img' => $productImage,
                    'full' => $productImage,
                    'caption' => '',
                    'position' => 0,
                    'isMain' => true,
                ];

                $sinchModel = \Magento\Framework\App\ObjectManager::getInstance()->get('Magebuzz\Sinchimport\Model\Sinch');
                $galleryPhotos = $sinchModel->loadGalleryPhotos($currentProduct->getId())->getGalleryPhotos();

                foreach ($galleryPhotos as $key => $galleryPhoto) {
                    $imagesItems[] = [
                        'thumb' => $galleryPhoto['thumb'],
                        'img' => $galleryPhoto['pic'],
                        'full' => $galleryPhoto['thumb'],
                        'caption' => '',
                        'position' => $key + 1,
                        'isMain' => false
                    ];
                }

                return json_encode($imagesItems);
            }
        }

        return $result;
    }
}
