<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite;


class QueryBuilder {

    public function afterCreate(\Smile\ElasticsuiteCore\Search\Request\Query\Fulltext\QueryBuilder $_subject, $result)
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/joe_search_stuff.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);

        $logger->info(json_encode($result));

        return $result;
    }
}