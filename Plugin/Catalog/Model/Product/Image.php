<?php

namespace SITC\Sinchimport\Plugin\Catalog\Model\Product;

use Magento\Framework\Image as MagentoImage;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Image
{
    /**
     * @var string
     */
    protected $_baseFileTmp;

    /**
     * @var bool
     */
    protected $_isBaseFilePlaceholderTmp;

    /**
     * @var MagentoImage
     */
    protected $_processorTmp;

    /**
     * @var \Magento\Framework\Image\Factory
     */
    protected $_imageFactory;

    public function __construct(
        \Magento\Framework\Image\Factory $imageFactory
    ) {
        $this->_imageFactory = $imageFactory;
        $this->_baseFileTmp = false;
    }

    public function aroundSetBaseFile(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed,
        $file
    ) {
        $result = $proceed($file);

        if (substr($file, 0, 4) == 'http') {
            $this->_isBaseFilePlaceholderTmp = false;
            $this->_baseFileTmp              = $file;
        }

        return $result;
    }

    /**
     * @return string
     */
    public function aroundGetBaseFile(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        return false;
    }

    /**
     * @return string
     */
    public function aroundGetNewFile(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        if ($this->_baseFileTmp) {
            return $this->_baseFileTmp;
        }

        return $proceed();
    }

    /**
     * Retrieve 'true' if image is a base file placeholder
     *
     * @return bool
     */
    public function aroundIsBaseFilePlaceholder(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        if ($this->_baseFileTmp) {
            return (bool)$this->_isBaseFilePlaceholderTmp;
        } else {
            return $proceed();
        }
    }

    /**
     * Return resized product image information
     *
     * @return array
     */
    public function aroundGetResizedImageInfo(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        if ($this->_baseFileTmp) {
            return [150, 150];
        }

        return $proceed();
    }

    /**
     * @return string
     */
    public function aroundGetUrl(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        if ($this->_baseFileTmp) {
            return $this->_baseFileTmp;
        }

        return $proceed();
    }

    /**
     * @return MagentoImage
     */
    public function aroundGetImageProcessor(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        if (!$this->_processorTmp) {
            $this->_processorTmp = $this->_imageFactory->create();
            $subject->setImageProcessor($this->_processorTmp);
        }

        return $proceed();
    }

    /**
     * @return bool
     */
    public function aroundIsCached(
        \Magento\Catalog\Model\Product\Image $subject,
        \Closure $proceed
    ) {
        if ($this->_baseFileTmp) {
            $result = true;
        } else {
            $result = $proceed();
        }

        return $result;
    }
}
