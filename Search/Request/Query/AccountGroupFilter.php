<?php
namespace SITC\Sinchimport\Search\Request\Query;

use SITC\Sinchimport\Search\Request\QueryInterface;
/**
 * Account group filtering implementation.
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class AccountGroupFilter implements QueryInterface
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var int
     */
    private $account_group;
    /**
     * @var boolean
     */
    private $cached;
    /**
     * Constructor.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param int       $account_group   The current users account group
     * @param string    $name            Query name.
     * @param boolean   $cached          Should the query be cached or not.
     */
    public function __construct(
        int $account_group = 0,
        $name = null,
        $cached = false
    ) {
        $this->account_group = $account_group;
        $this->name = $name;
        $this->cached = $cached;
    }
    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return 'sitcAccountGroupQuery';
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
        return QueryInterface::DEFAULT_BOOST_VALUE;
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
}
