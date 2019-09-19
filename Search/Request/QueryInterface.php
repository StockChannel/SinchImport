<?php

namespace SITC\Sinchimport\Search\Request;
/**
 * This interface exists solely for the purpose of allowing setup:di:compile to complete if the underlying interface doesn't exist
 */

if(interface_exists('\Smile\ElasticsuiteCore\Search\Request\QueryInterface')) {
    interface QueryInterface extends \Smile\ElasticsuiteCore\Search\Request\QueryInterface {}
} else {
    interface QueryInterface {}
}