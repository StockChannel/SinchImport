<?php
namespace SITC\Sinchimport\Search\Request\Query;

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
/**
 * Match on category name and boost results accordingly
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class CategoryBoostFilter implements QueryInterface
{
    /** @var string Query Name */
    private $name;
    /** @var string[] Query to match Category Name */
    private $categories;
    /** @var boolean */
    private $cached;

    /**
     * Constructor.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string[] $queries The category name to match
     * @param null $name Query name.
     * @param boolean $cached Should the query be cached or not.
     */
    public function __construct(
        array $queries,
        $name = null,
        $cached = false
    ) {
        $this->categories = $queries;
        $this->name = $name;
        $this->cached = $cached;
    }

    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return 'sitcCategoryBoostQuery';
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
     * Category name to match
     *
     * @return string
     */
    public function getCategories()
    {
        return $this->categories;
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
