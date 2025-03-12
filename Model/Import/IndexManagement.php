<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\ActionFactory;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Indexer\StateInterfaceFactory;
use Magento\Indexer\Model\Processor;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Product\UrlFactory;
use Symfony\Component\Console\Output\ConsoleOutput;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use function time;

class IndexManagement {
    private StateInterfaceFactory $stateFactory;
    private ConfigInterface $indexerConfig;
    private IndexerRegistry $indexerRegistry;
    private ActionFactory $indexActionFactory;
    private Data $helper;
    private ConsoleOutput $output;
    private Logger $logger;

    public function __construct(
        StateInterfaceFactory $stateFactory,
        ConfigInterface $indexerConfig,
        IndexerRegistry $indexerRegistry,
        ActionFactory $indexActionFactory,
        Data $helper,
        ConsoleOutput $output,
        Logger $logger,
        private readonly ResourceConnection $resourceConn,
        private readonly CollectionFactory $indexersFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Processor $indexProcessor,
        private readonly UrlFactory $productUrlFactory,
        private readonly Pool $cacheFrontendPool,
    ){
        $this->stateFactory = $stateFactory;
        $this->indexerConfig = $indexerConfig;
        $this->indexerRegistry = $indexerRegistry;
        $this->indexActionFactory = $indexActionFactory;
        $this->helper = $helper;
        $this->output = $output;
        $this->logger = $logger->withName("IndexManagement");
    }

    /**
     * Ensure no indexers are running, this should be run at the start of the import
     * Will wait for index completion if sinchimport/general/wait_for_index_completion is true
     * 
     * @return bool True if no indexers are currently in the "working" state
     */
    public function ensureIndexersNotRunning(): bool
    {
        $waitForIndexers = $this->helper->getStoreConfig('sinchimport/general/wait_for_index_completion');
        if($waitForIndexers) {
            $this->waitForIndexCompletion();
        }
        return $this->noIndexersRunning();
    }
    
    /**
     * Check the state of the indexers
     * Returns true if all indexers are NOT running (none in state "working") and false otherwise
     * 
     * @return bool
     */
    private function noIndexersRunning(): bool
    {
        foreach(array_keys($this->indexerConfig->getIndexers()) as $indexerId) {
            $indexerState = $this->stateFactory->create();
            $indexerState->loadByIndexer($indexerId);
            if ($indexerState->getStatus() == StateInterface::STATUS_WORKING) {
                return false;
            }
        }
        return true;
    }

    /**
     * Doesn't return until the none of the indexers are in the "working" state, or 30 minutes has passed
     * 
     * @return void
     */
    private function waitForIndexCompletion(): void
    {
        $waitStart = time();
        while(!$this->noIndexersRunning()) {
            sleep(5);
            $now = time();
            if($now - $waitStart > 1800) {
                $this->print("Waited 30 minutes for index completion, abandoning...");
                break;
            }
        }
    }

    private function print(string $message): void
    {
        $this->output->writeln($message);
        $this->logger->info($message);
    }

    /**
     * Run a full reindex of the index with the given name if this is a not a FULL import
     * and indexing separately is disabled, otherwise invalidate the index
     * @param string $indexerName
     * @return void
     */
    public function runIndex(string $indexerName): void
    {
        //Only actually run the index if "Indexing separately" is off and the current import is not a full import
        // (as the full import runs a full reindex at the end anyway, and it's just a waste of time to do the index twice)
        if ($this->helper->currentImportType() == 'FULL') return;
        $indexer = $this->indexerRegistry->get($indexerName);
        if ($this->helper->indexSeparately()) {
            $indexer->invalidate();
        } else {
            // We have to pass an empty array called data into the params, otherwise
            // attempts to run the catalogsearch_fulltext index result in crashes
            $indexActions = $this->indexActionFactory->create($indexer->getActionClass(), ['data' => []]);
            $indexActions->executeFull();
        }
    }

    public function runFullIndex(): void
    {
        $this->indexProcessor->reindexAll();
        //Clear changelogs explicitly after finishing a full reindex
        $this->indexProcessor->clearChangelog();
        //Then make sure all materialized views reflect actual state
        $this->indexProcessor->updateMview();

        $configTonerFinder = $this->helper->getStoreConfig('sinchimport/general/index_tonerfinder');
        if ($configTonerFinder == 1) {
            $this->insertCategoryIdForFinder();
        } else {
            $this->print("Configuration ignores indexing tonerfinder");
        }
    }

    /**
     * @insertCategoryIdForFinder
     */
    public function insertCategoryIdForFinder(): void
    {
        $tbl_store = $this->resourceConn->getTableName('store');
        $tbl_cat = $this->resourceConn->getTableName('catalog_category_product');

        $conn = $this->resourceConn->getConnection();
        //TODO: Remove operations on index tables
        $conn->query("INSERT INTO {$this->resourceConn->getTableName('catalog_category_product_index')} (
            category_id, product_id, position, is_parent, store_id, visibility) (
                SELECT ccp.category_id, ccp.product_id, ccp.position, 1, store.store_id, 4
                FROM {$tbl_cat} ccp
                JOIN {$tbl_store} store
            )
            ON DUPLICATE KEY UPDATE visibility = 4"
        );

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = $store->getId();

            //TODO: Remove operations on index tables
            $table = $this->resourceConn->getTableName('catalog_category_product_index_store' . $storeId);
            if ($conn->isTableExists($table)) {
                $conn->query("INSERT INTO {$table} (category_id, product_id, position, is_parent, store_id, visibility) (
                      SELECT ccp.category_id, ccp.product_id, ccp.position, 1, store.store_id, 4
                      FROM {$tbl_cat} ccp
                        JOIN {$tbl_store} store
                ) ON DUPLICATE KEY UPDATE visibility = 4"
                );
            }
        }
    }

    public function invalidateIndexers(): void
    {
        /**
         * @var IndexerInterface[] $indexers
         */
        $indexers = $this->indexersFactory->create()->getItems();
        foreach ($indexers as $indexer) {
            $this->print("Invalidating index: " . $indexer->getId());
            $indexer->invalidate();
        }
        $this->print("Indexes invalidated");
    }

    public function reindexProductUrls(): void
    {
        $url_rewrite = $this->resourceConn->getTableName('url_rewrite');
        $catalog_product_entity_varchar = $this->resourceConn->getTableName('catalog_product_entity_varchar');

        $conn = $this->resourceConn->getConnection();
        $conn->query("DELETE FROM $url_rewrite WHERE is_autogenerated = 1 AND entity_type = 'product'");
        $conn->query(
            "UPDATE $catalog_product_entity_varchar SET value = '' WHERE attribute_id = :attrId",
            [':attrId' => $this->helper->getProductAttributeId('url_key')]
        );

        $this->productUrlFactory->create()->refreshRewrites();
    }

    public function clearCaches(): void
    {
        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
            $cacheFrontend->clean();
        }
    }
}
