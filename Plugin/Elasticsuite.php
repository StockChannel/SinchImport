<?php
namespace SITC\Sinchimport\Plugin;

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

class Elasticsuite {
    /**
     * @var \SITC\Sinchimport\Helper\Data $helper
     */
    private $helper;
    /**
     * @var \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory
     */
    private $queryFactory;

    public function __construct(
        \SITC\Sinchimport\Helper\Data $helper,
        \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory
    ){
        $this->helper = $helper;
        $this->queryFactory = $queryFactory;
    }

    /**
     * Add Elasticsearch filter for Account group to the request being built
     *
     * @param \Smile\ElasticsuiteCore\Search\Request\Builder $subject
     * @param integer               $storeId       Search request store id.
     * @param string                $containerName Search request name.
     * @param integer               $from          Search request pagination from clause.
     * @param integer               $size          Search request pagination size.
     * @param string|QueryInterface $query         Search request query.
     * @param array                 $sortOrders    Search request sort orders.
     * @param array                 $filters       Search request filters.
     * @param QueryInterface[]      $queryFilters  Search request filters prebuilt as QueryInterface.
     * @param array                 $facets        Search request facets.
     *
     * @return array|null
     */
    public function beforeCreate(
        $subject,
        $storeId,
        $containerName,
        $from,
        $size,
        $query = null,
        $sortOrders = [],
        $filters = [],
        $queryFilters = [],
        $facets = []
    ){
        if(!$this->helper->isProductVisibilityEnabled()) return null;
        
        $filterParam = $this->buildEsQuery();
        if(!in_array($filterParam, $queryFilters)) {
            $queryFilters[] = $filterParam;
        }

        return [
            $storeId,
            $containerName,
            $from,
            $size,
            $query,
            $sortOrders,
            $filters,
            $queryFilters,
            $facets
        ];
    }

    private function buildEsQuery()
    {
        return $this->queryFactory->create(
            'sitcAccountGroupQuery',
            ['account_group' => $this->helper->getCurrentAccountGroupId()]
        );
    }
}