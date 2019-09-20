<?php

namespace SITC\Sinchimport\Search\Adapter\Elasticsuite\Request\Query;
/**
 * This interface exists solely for the purpose of allowing setup:di:compile to complete if the underlying interface doesn't exist
 */

if(interface_exists('\Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\BuilderInterface')) {
    interface BuilderInterface extends \Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\BuilderInterface {}
} else {
    interface BuilderInterface {}
}
