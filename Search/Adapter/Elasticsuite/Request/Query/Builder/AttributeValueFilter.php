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
 * Build an ES query to boost results with matching attribute values
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class AttributeValueFilter extends AbstractComplexBuilder implements BuilderInterface
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
        if ($query->getType() !== 'sitcAttributeValueQuery') {
            throw new InvalidArgumentException("Query builder : invalid query type {$query->getType()}");
        }

        return $this->parentBuilder->buildQuery(
            $this->queryFactory->create(
                QueryInterface::TYPE_MATCH,
                [
                    'field' => $query->getAttribute(),
                    'queryText' => $query->getValue(),
                    'boost' => $query->getBoost()
                ]
            )
        );
    }
}