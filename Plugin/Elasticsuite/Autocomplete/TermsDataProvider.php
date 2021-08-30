<?php

namespace SITC\Sinchimport\Plugin\Elasticsuite\Autocomplete;

use Magento\Search\Model\Autocomplete\ItemInterface;
use SITC\Sinchimport\Helper\Data;
use Magento\Search\Model\QueryFactory;
use SITC\Sinchimport\Helper\SearchProcessing;
use Smile\ElasticsuiteCore\Model\Autocomplete\Terms\DataProvider as ESDataProvider;

class TermsDataProvider {
	const AUTOCOMPLETE_TYPE = 'term';

    private Data $helper;
    private QueryFactory $queryFactory;
    private SearchProcessing $searchProcessing;

    public function __construct(
		Data $helper,
        QueryFactory $queryFactory,
        SearchProcessing $searchProcessing
    ){
		$this->helper = $helper;
		$this->queryFactory = $queryFactory;
        $this->searchProcessing = $searchProcessing;
	}

	/**
	 * Interceptor for displaying autocomplete terms in quick search
	 *
	 * @param ESDataProvider $subject
	 * @param ItemInterface[] $result The return of the intercepted method
	 *
	 * @return ItemInterface[]
	 */
	public function afterGetItems(ESDataProvider $subject, array $result): array
	{
        $suggestions = [];
		if ($this->helper->experimentalSearchEnabled()) {
            $queryText = $this->queryFactory->get()->getQueryText();
            $suggestions = $this->searchProcessing->getAutocompleteSuggestions($queryText);
		}
		return array_merge($suggestions, $result);
	}
}