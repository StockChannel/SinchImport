<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Block\Product\View;

class Gallery
{
    protected $sinch;
    
    public function __construct(
        \Magebuzz\Sinchimport\Model\Sinch $sinch
    ) {
        $this->sinch = $sinch;
    }
    
    /**
     * Retrieve product images in JSON format
     *
     * @return string
     */
    public function afterGetGalleryImagesJson($subject, $result)
    {
        $currentProduct = $subject->getProduct();
        if ($productImage = $currentProduct->getImage()) {
            if (substr($productImage, 0, 4) == 'http') {
                $imagesItems[] = [
                    'thumb'    => $productImage,
                    'img'      => $productImage,
                    'full'     => $productImage,
                    'caption'  => '',
                    'position' => 0,
                    'isMain'   => true,
                ];
                
                $galleryPhotos = $this->sinch->loadGalleryPhotos(
                    $currentProduct->getId()
                )->getGalleryPhotos();
                
                foreach ($galleryPhotos as $key => $galleryPhoto) {
                    $imagesItems[] = [
                        'thumb'    => $galleryPhoto['thumb'],
                        'img'      => $galleryPhoto['pic'],
                        'full'     => $galleryPhoto['pic'],
                        'caption'  => '',
                        'position' => $key + 1,
                        'isMain'   => false
                    ];
                }
                
                return json_encode($imagesItems);
            }
        }
        
        return $result;
    }
}
