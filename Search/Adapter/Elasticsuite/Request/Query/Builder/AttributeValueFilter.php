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
use Magento\Framework\App\ResourceConnection;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\Builder;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\Builder\AbstractComplexBuilder;
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
    private readonly string $eav_attribute_option_value;
    private readonly string $eav_attribute_option;
    private readonly string $eav_attribute;

    public function __construct(
        Builder                             $builder,
        private readonly QueryFactory\Proxy $queryFactory,
        private readonly ResourceConnection $resourceConn,
    ) {
        parent::__construct($builder);
        $this->eav_attribute_option_value = $this->resourceConn->getTableName('eav_attribute_option_value');
        $this->eav_attribute_option = $this->resourceConn->getTableName('eav_attribute_option');
        $this->eav_attribute = $this->resourceConn->getTableName('eav_attribute');
    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryInterface $query): bool|array
    {
        if ($query->getType() !== 'sitcAttributeValueQuery') {
            throw new InvalidArgumentException("Query builder : invalid query type {$query->getType()}");
        }

        return $this->parentBuilder->buildQuery(
            $this->queryFactory->create(
                QueryInterface::TYPE_MATCH,
                [
                    'field' => $query->getAttribute(),
                    'queryText' => $this->getOptionIdFromValue($query->getAttribute(), $query->getValue()),
                    'boost' => $query->getBoost()
                ]
            )
        );
    }


    private function getOptionIdFromValue(string $attributeCode, string $value): ?int
    {
        return $this->resourceConn->getConnection()->fetchOne(
            "SELECT eao.option_id FROM {$this->eav_attribute_option_value} eaov
                        INNER JOIN {$this->eav_attribute_option} eao
                            ON eaov.option_id = eao.option_id
                        INNER JOIN {$this->eav_attribute} ea
                            ON eao.attribute_id = ea.attribute_id
                        WHERE ea.attribute_code = :attribute
                            AND eaov.value = :value
                        ORDER BY CHAR_LENGTH(eaov.value) DESC LIMIT 1",
            [":attribute" => $attributeCode, "value" => $value]
        );
    }
}