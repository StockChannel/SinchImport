<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile ElasticSuite to newer
 * versions in the future.
 *
 * @category  SITC
 * @package   SITC\Sinchimport
 * @author    Nick Anstee <nick.anstee@stockinthechannel.com>
 * @copyright 2019 StockChannel Ltd
 * @license   Open Software License ("OSL") v. 3.0
 */
namespace SITC\Sinchimport\Search\Adapter\Elasticsuite\Request\Query\Builder;

use InvalidArgumentException;
use SITC\Sinchimport\Helper\Data;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\Builder;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\Builder\AbstractComplexBuilder;
use Smile\ElasticsuiteCore\Search\Request\Query\Nested;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\BuilderInterface;

/**
 * Build an ES query to boost results in categories matching the query.
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class CategoryBoostFilter extends AbstractComplexBuilder implements BuilderInterface
{
    /** @var QueryFactory $queryFactory */
    private $queryFactory;
    /** @var Data $helper */
    private $helper;

    public function __construct(Builder $builder, QueryFactory\Proxy $queryFactory, Data $helper) {
        parent::__construct($builder);
        $this->queryFactory = $queryFactory;
        $this->helper = $helper;
    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryInterface $query)
    {
        if ($query->getType() !== 'sitcCategoryBoostQuery') {
            throw new InvalidArgumentException("Query builder : invalid query type {$query->getType()}");
        }

        return $this->parentBuilder->buildQuery(
            $this->queryFactory->create(
                QueryInterface::TYPE_NESTED,
                [
                    'path' => 'category',
                    'query' => $this->queryFactory->create(
                        QueryInterface::TYPE_MATCH,
                        [
                            'field' => 'category.name',
                            'queryText' => $query->getCategory(),
                            'boost' => $this->helper->getStoreConfig('sinchimport/search/category_field_search_weight')
                        ]
                    ),
                    'scoreMode' => Nested::SCORE_MODE_MAX,
                    'boost' => 1
                ]
            )
        );
    }
}