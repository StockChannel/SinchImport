<?php
namespace SITC\Sinchimport\Search\Request\Query;

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
/**
 * Match on attribute values
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class AttributeValueFilter implements QueryInterface
{
    private string $attribute;
    private string $value;

    private ?string $name;
    private bool $cached;


    public function __construct(
        string $attribute,
        string $value,
        $name = null,
        $cached = false
    ) {
        $this->attribute = $attribute;
        $this->value = $value;
        $this->name = $name;
        $this->cached = $cached;
    }

    /**
     * {@inheritDoc}
     */
    public function getType(): string
    {
        return 'sitcAttributeValueQuery';
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
    public function getBoost(): ?int
    {
        return QueryInterface::DEFAULT_BOOST_VALUE;
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function getValue(): string
    {
        return $this->value;
    }

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
