<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Helper;

class Image extends \Magento\Catalog\Helper\Image
{

    /**
     * Initialize Helper to work with Image
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string $imageId
     * @param array $attributes
     * @return $this
     */
    public function init($product, $imageId, $attributes = [])
    {
        $this->_reset();

        if ($product->getSinchProductId()) {
            $attributes = array_merge($attributes, ['width' => 150, 'height' => 150]);
        }

        $this->attributes = array_merge(
            $this->getConfigView()->getMediaAttributes('Magento_Catalog', self::MEDIA_TYPE_CONFIG_NODE, $imageId),
            $attributes
        );

        $this->setProduct($product);
        $this->setImageProperties();
        $this->setWatermarkProperties();

        return $this;
    }

    /**
     * Apply scheduled actions
     *
     * @return $this
     * @throws \Exception
     */
    protected function applyScheduledActions()
    {
        $this->initBaseFile();
        $model = $this->_getModel();
        if (substr($model->getBaseFile(),0,4) != 'http' && $this->isScheduledActionsAllowed()) {
	        if ($this->_scheduleRotate) {
	            $model->rotate($this->getAngle());
	        }
	        if ($this->_scheduleResize) {
	            $model->resize();
	        }
	        if ($this->getWatermark()) {
	            $model->setWatermark($this->getWatermark());
	        }
	        $model->saveFile();
	    }
        return $this;
    }
}
