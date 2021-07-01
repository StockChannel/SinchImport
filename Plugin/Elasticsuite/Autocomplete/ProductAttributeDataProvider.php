<?php


namespace SITC\Sinchimport\Plugin\Elasticsuite\Autocomplete;

use Magento\Search\Model\Autocomplete\ItemInterface;
use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCatalog\Model\Autocomplete\Product\Attribute\DataProvider;

class ProductAttributeDataProvider
{
    const BOOST_ATTRIBUTES = ['sinch_family', 'sinch_family_series'];

    private Data $helper;

    public function __construct(
        Data $helper
    ){
        $this->helper = $helper;
    }

    /**
     * Interceptor for reordering product attribute autocomplete suggestions
     * The aim being to prioritize Family and Family Series, which are more likely to be relevant
     * @param DataProvider $subject
     * @param ItemInterface[] $result The return of the intercepted method
     *
     * @return ItemInterface[]
     */
    public function afterGetItems(DataProvider $subject, array $result): array
    {
        if ($this->helper->experimentalSearchEnabled()) {
            uasort($result, [$this, 'prioritizeFamily']);
        }

        return $result;
    }

    private function prioritizeFamily($item1, $item2): int
    {
        $oneIsBoostable = in_array($item1->getAttributeCode(), self::BOOST_ATTRIBUTES);
        $twoIsBoostable = in_array($item2->getAttributeCode(), self::BOOST_ATTRIBUTES);
        if ($oneIsBoostable && !$twoIsBoostable) {
            return -1;
        } else if ($twoIsBoostable && !$oneIsBoostable) {
            return 1;
        }
        return 0;
    }
}