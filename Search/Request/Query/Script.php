<?php
namespace SITC\Sinchimport\Search\Request\Query;

use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
/**
 * script queries request implementation.
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class Script implements QueryInterface
{
    /**
     * @var string
     */
    private $name;
    /**
     * @var string
     */
    private $source;
    /**
     * @var string
     */
    private $lang;
    /**
     * @var array
     */
    private $params;
    /**
     * @var boolean
     */
    private $cached;
    /**
     * Constructor.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @param string    $source   Source for the scripted field
     * @param array     $params    Script parameters
     * @param string    $lang     Script language (painless by default)
     * @param string    $name               Query name.
     * @param integer   $boost              Query boost.
     * @param boolean   $cached             Should the query be cached or not.
     */
    public function __construct(
        string $source = "id",
        array $params = [],
        string $lang = "painless",
        $name = null,
        $cached = false
    ) {
        $this->source = $source;
        $this->params = $params;
        $this->lang = $lang;
        $this->name = $name;
        $this->cached = $cached;
    }
    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return 'scriptQuery';
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
     * Script language
     *
     * @return string
     */
    public function getLang()
    {
        return $this->lang;
    }
    /**
     * Script source
     *
     * @return string
     */
    public function getSource()
    {
        return $this->source;
    }
    /**
     * Script params
     *
     * @return array
     */
    public function getParams()
    {
        return $this->params;
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
