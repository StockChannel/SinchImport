<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model\Product;

class Image extends \Magento\Catalog\Model\Product\Image
{
    public function setBaseFile($file)
    {
        $this->_isBaseFilePlaceholder = false;

        if (substr($file,0,4) != 'http') {
            if ($file && 0 !== strpos($file, '/', 0)) {
                $file = '/' . $file;
            }
            $baseDir = $this->_catalogProductMediaConfig->getBaseMediaPath();

            if ('/no_selection' == $file) {
                $file = null;
            }
            if ($file) {
                if (!$this->_fileExists($baseDir . $file) || !$this->_checkMemory($baseDir . $file)) {
                    $file = null;
                }
            }
            if (!$file) {
                $this->_isBaseFilePlaceholder = true;
                // check if placeholder defined in config
                $isConfigPlaceholder = $this->_scopeConfig->getValue(
                    "catalog/placeholder/{$this->getDestinationSubdir()}_placeholder",
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
                $configPlaceholder = '/placeholder/' . $isConfigPlaceholder;
                if (!empty($isConfigPlaceholder) && $this->_fileExists($baseDir . $configPlaceholder)) {
                    $file = $configPlaceholder;
                } else {
                    $this->_newFile = true;
                    return $this;
                }
            }

            $baseFile = $baseDir . $file;
        } else {
            $baseFile = $file;
        }
        if ((!$file) && (!$this->_mediaDirectory->isFile($baseFile)) && substr($baseFile,0,4) != 'http') {
            throw new \Exception(__('We can\'t find the image file.'));
        }

        $this->_baseFile = $baseFile;

        // build new filename (most important params)
        $path = [
            $this->_catalogProductMediaConfig->getBaseMediaPath(),
            'cache',
            $this->_storeManager->getStore()->getId(),
            $path[] = $this->getDestinationSubdir(),
        ];
        if (!empty($this->_width) || !empty($this->_height)) {
            $path[] = "{$this->_width}x{$this->_height}";
        }

        // add misk params as a hash
        $miscParams = [
            ($this->_keepAspectRatio ? '' : 'non') . 'proportional',
            ($this->_keepFrame ? '' : 'no') . 'frame',
            ($this->_keepTransparency ? '' : 'no') . 'transparency',
            ($this->_constrainOnly ? 'do' : 'not') . 'constrainonly',
            $this->_rgbToString($this->_backgroundColor),
            'angle' . $this->_angle,
            'quality' . $this->_quality,
        ];

        // if has watermark add watermark params to hash
        if ($this->getWatermarkFile()) {
            $miscParams[] = $this->getWatermarkFile();
            $miscParams[] = $this->getWatermarkImageOpacity();
            $miscParams[] = $this->getWatermarkPosition();
            $miscParams[] = $this->getWatermarkWidth();
            $miscParams[] = $this->getWatermarkHeight();
        }

        $path[] = md5(implode('_', $miscParams));

        // append prepared filename
        if (substr($file,0,4) != 'http') {
            $this->_newFile = implode('/', $path) . $file; // the $file contains heading slash
        }
        else {
            $this->_newFile = $file;
        }
        // the $file contains heading slash
        return $this;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        if ($this->_newFile === true) {
            $url = $this->_assetRepo->getUrl(
                "Magento_Catalog::images/product/placeholder/{$this->getDestinationSubdir()}.jpg"
            );
        } elseif (substr($this->_newFile,0,4) == 'http') {
            $url = $this->_newFile;
        } else {
            $url = $this->_storeManager->getStore()->getBaseUrl(
                \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
            ) . $this->_newFile;
        }

        return $url;
    }
}
