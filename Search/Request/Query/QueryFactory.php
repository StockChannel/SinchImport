<?php

namespace SITC\Sinchimport\Search\Request\Query;
/**
 * This class exists solely for the purpose of allowing setup:di:compile to complete if the underlying interface doesn't exist
 */

if(class_exists('\Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory')) {
    class QueryFactory {
        /** @var \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory */
        private $factory;

        public function __construct(
            \Smile\ElasticsuiteCore\Search\Request\Query\QueryFactory $queryFactory
        ){
            $this->factory = $queryFactory;
        }

        public function create($queryType, $queryParams = [])
        {
            return $this->factory->create($queryType, $queryParams);
        }
    }
} else {
    class QueryFactory {
        public function create($_queryType, $queryParams = [])
        {
            throw new \LogicException("Elasticsuite is not available");
        }
    }
}