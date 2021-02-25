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

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\BuilderInterface;

/**
 * Build an ES query to boost results in categories matching the query.
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class CategoryBoostFilter implements BuilderInterface
{
    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryInterface $query)
    {
        if ($query->getType() !== 'sitcCategoryBoostQuery') {
            throw new \InvalidArgumentException("Query builder : invalid query type {$query->getType()}");
        }

        return [
            "match" => [
                "category.name" => [
                    "query" => $query->getCategory(),
                    "boost" => $query->getBoost()
                ]
            ]
        ];
    }
}