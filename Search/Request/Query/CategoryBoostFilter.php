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
    private ?string $name;
    /** @var string[] Query to match Category Name */
    private array $categories;
    private bool $filter;
    private bool $cached;

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
        bool $filter = false,
        $name = null,
        $cached = false
    ) {
        $this->categories = $queries;
        $this->filter = $filter;
        $this->name = $name;
        $this->cached = $cached;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'sitcCategoryBoostQuery';
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getBoost(): ?int
    {
        return QueryInterface::DEFAULT_BOOST_VALUE;
    }

    /**
     * Category name to match
     *
     * @return string[]
     */
    public function getCategories(): array
    {
        return $this->categories;
    }

    public function getMinShouldMatch(): int
    {
        return $this->filter ? 1 : 0;
    }

    /**
     * Indicates if the bool query needs to be cached or not.
     *
     * @return boolean
     */
    public function isCached(): bool
    {
        return $this->cached;
    }

    public function setName(string $name): QueryInterface
    {
        $this->name = $name;
        return $this;
    }
}
