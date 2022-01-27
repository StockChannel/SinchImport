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
use Smile\ElasticsuiteCore\Search\Request\QueryInterface;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Request\Query\BuilderInterface;

/**
 * Build an ES query to limit the selection to just products visible to the current account group.
 *
 * @category SITC
 * @package  SITC\Sinchimport
 * @author   Nick Anstee <nick.anstee@stockinthechannel.com>
 */
class AccountGroupFilter implements BuilderInterface
{
    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryInterface $query)
    {
        if ($query->getType() !== 'sitcAccountGroupQuery') {
            throw new InvalidArgumentException("Query builder : invalid query type {$query->getType()}");
        }

        // {
        //     "query": {
        //       "constant_score": {
        //         "filter": {
        //           "bool": {
        //             "should": [
        //               {
        //                 "bool": {
        //                   "must": [
        //                     {
        //                       "prefix": {
        //                         "sinch_restrict": "!"
        //                       }
        //                     },
        //                     {
        //                       "bool": {
        //                         "must_not": {
        //                           "script": {
        //                             "script": {
        //                               "source": """Arrays.asList(/,/.split(doc['sinch_restrict'].value.replace("!", ""))).contains(params.group_id)""",
        //                               "params": {
        //                                 "group_id": "2518"
        //                               }
        //                             }
        //                           }
        //                         }
        //                       }
        //                     }
        //                   ]
        //                 }
        //               },
        //               {
        //                 "bool": {
        //                   "must": [
        //                     {
        //                       "script": {
        //                         "script": {
        //                           "source": "Arrays.asList(/,/.split(doc['sinch_restrict'].value)).contains(params.group_id)",
        //                           "params": {
        //                             "group_id": "2518"
        //                           }
        //                         }
        //                       }
        //                     },
        //                     {
        //                       "bool": {
        //                         "must_not": [
        //                           {
        //                             "prefix": {
        //                               "sinch_restrict": "!"
        //                             }
        //                           }
        //                         ]
        //                       }
        //                     }
        //                   ]
        //                 }
        //               },
        //               {
        //                 "bool": {
        //                   "must_not": {
        //                     "exists": {
        //                       "field": "sinch_restrict"
        //                     }
        //                   }
        //                 }
        //               }
        //             ]
        //           }
        //         }
        //       }
        //     }
        //   }

        $blacklistCriteria = [
            "bool" => [
                "must" => [
                    [
                        "prefix" => [
                            "sinch_restrict" => "!"
                        ]
                    ],
                    [
                        "bool" => [
                            "must_not" => [
                                "script" => [
                                    "script" => [
                                        "source" => "Arrays.asList(/,/.split(doc['sinch_restrict'].value.replace(\"!\", \"\"))).contains(params.group_id)",
                                        "params" => [
                                            "group_id" => (string)$query->getAccountGroup()
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $whitelistCriteria = [
            "bool" => [
                "must" => [
                    [
                        "script" => [
                            "script" => [
                                "source" => "Arrays.asList(/,/.split(doc['sinch_restrict'].value)).contains(params.group_id)",
                                "params" => [
                                    "group_id" => (string)$query->getAccountGroup()
                                ]
                            ]
                        ]
                    ],
                    [
                        "bool" => [
                            "must_not" => [
                                [
                                    "prefix" => [
                                        "sinch_restrict" => "!"
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        return [
            "constant_score" => [
                "filter" => [
                    "bool" => [
                        "should" => [
                            $blacklistCriteria,
                            $whitelistCriteria,
                            [
                                "bool" => [
                                    "must_not" => [
                                        "exists" => [
                                            "field" => "sinch_restrict"
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
}
