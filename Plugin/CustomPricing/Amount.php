<?php
namespace SITC\Sinchimport\Plugin\CustomPricing;

use \Magento\Framework\Pricing\Render\AmountRenderInterface;

//Interceptor on \Magento\Framework\Pricing\Render\AmountRenderInterface
class Amount {
    /**
     * @var \SITC\Sinchimport\Helper\Data $helper
     */
    private $helper;
    /**
     * @var \SITC\Sinchimport\Helper\CustomPricing $customPricingHelper
     */
    private $customPricingHelper;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \SITC\Sinchimport\Helper\CustomPricing $customPricingHelper
    ){
        $this->helper = $helper;
        $this->customPricingHelper = $customPricingHelper;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/test.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
    }

    /**
     * Interceptor for getPrice
     * @param \Magento\Framework\Pricing\Render\AmountRenderInterface $subject The intercepted class
     * @param \Magento\Framework\Pricing\Price\PriceInterface $result The original return
     */
    public function afterGetPrice(AmountRenderInterface $subject, $result)
    {
        if(!$this->helper->isModuleEnabled('Tigren_CompanyAccount')) {
            return $result;
        }

        $this->logger->info("AmountRenderInterface getPrice");

        return $result;
    }

    /**
     * Interceptor for getDisplayValue
     * @param \Magento\Framework\Pricing\Render\AmountRenderInterface $subject The intercepted class
     * @param float $result The original result
     */
    public function afterGetDisplayValue(AmountRenderInterface $subject, float $result)
    {
        if(!$this->helper->isModuleEnabled('Tigren_CompanyAccount')) {
            return $result;
        }

        $prod = $subject->getSaleableItem();
        $accountGroup = $this->helper->getCurrentAccountGroupId();
        $newPrice = $this->customPricingHelper->getAccountGroupPrice($accountGroup, $prod);
        if(empty($newPrice)) {
            $this->logger->info("Product has no custom price: " . $prod->getSku());
            return $result;
        }
        
        $this->logger->info("AmountRenderInterface getDisplayValue. Was {$result}, now {$newPrice} for product: " . $prod->getSku());
        return $newPrice;
    }
}