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
class AccountGroupFilter implements QueryInterface
{
    private ?string $name;
    private int $account_group = 0;
    private bool $cached;


    public function __construct(
        ?int   $account_group = 0,
        string $name = null,
        bool $cached = false
    ) {
        if(is_numeric($account_group)) {
            $this->account_group = (int)$account_group;
        }
        $this->name = $name;
        $this->cached = $cached;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'sitcAccountGroupQuery';
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
     * Account group
     *
     * @return int
     */
    public function getAccountGroup(): int
    {
        return $this->account_group;
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
