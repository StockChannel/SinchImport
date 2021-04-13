<?php
namespace SITC\Sinchimport\Search\Request\Query;

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;

/**
 * Account group filtering implementation.
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class PriceRangeQuery implements QueryInterface
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var int
     */
    private $account_group = 0;
	/**
	 * @var array
	 */
    private $bounds;
    /**
     * @var boolean
     */
    private $cached;
    /**
     * Constructor.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param array     $bounds          Bounds for the range query
     * @param int|null  $account_group   The current users account group
     * @param string    $name            Query name.
     * @param boolean   $cached          Should the query be cached or not.
     */
    public function __construct(
    	$bounds = [],
        $account_group = 0,
        $name = null,
        $cached = false
    ) {
    	$this->bounds = $bounds;
        if(is_numeric($account_group)) {
            $this->account_group = (int)$account_group;
        }
        $this->name = $name;
        $this->cached = $cached;
    }
    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return 'sitcPriceRangeQuery';
    }
    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return $this->name;
    }
    /**
     * {@inheritDoc}
     */
    public function getBoost()
    {
        return 10.0;
    }

    /**
     * Account group
     *
     * @return int
     */
    public function getAccountGroup()
    {
        return $this->account_group;
    }

    /**
     * Indicates if the bool query needs to be cached or not.
     *
     * @return boolean
     */
    public function isCached()
    {
        return $this->cached;
    }

	/**
	 * The upper & lower bounds of the range
	 *
	 * @return array
	 */
    public function getBounds()
    {
    	return $this->bounds;
    }
}
