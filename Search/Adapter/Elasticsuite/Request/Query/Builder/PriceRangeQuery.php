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

use Magento\Customer\Model\Group;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\BuilderInterface;
use Smile\ElasticsuiteCore\Search\Request\Query\Nested;
use Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory;
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

/**
 * Build an ES query to boost search results based on price bounds
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class PriceRangeQuery implements BuilderInterface
{
	/** @var QueryFactory  */
	private $queryFactory;

	/**
	 * PriceRangeQuery constructor.
	 * @param QueryFactory $queryFactory
	 */
	public function __construct(
		QueryFactory $queryFactory
	){
		$this->queryFactory = $queryFactory;
	}

	/**
     * {@inheritDoc}
     */
    public function buildQuery(QueryInterface $query)
    {
        if ($query->getType() !== 'sitcPriceRangeQuery') {
            throw new \InvalidArgumentException("Query builder : invalid query type {$query->getType()}");
        }

	    $rangeQuery = $this->queryFactory->create(
		    QueryInterface::TYPE_RANGE,
		    ['field' => 'price.price', 'bounds' => $query->getBounds()]
	    );

	    $groupId = Group::NOT_LOGGED_IN_ID;
	    try {
		    $groupId = $query->getAccountGroup();
	    } catch (NoSuchEntityException | LocalizedException $e) {}


	    $customerGroupQuery = $this->queryFactory->create(
		    QueryInterface::TYPE_TERM,
		    ['field' => 'price.customer_group_id', 'value' => $groupId]
	    );

	    return $this->queryFactory->create(
		    QueryInterface::TYPE_NESTED,
		    [
			    'path' => 'price',
			    'query' => $this->queryFactory->create(
				    QueryInterface::TYPE_BOOL,
				    ['must' => [$rangeQuery, $customerGroupQuery]]
			    ),
			    'scoreMode' => Nested::SCORE_MODE_AVG,
			    'boost' => $query->getBoost()
		    ]
	    );
    }
}