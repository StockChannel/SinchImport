<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\Indexer\ActionFactory;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Indexer\StateInterfaceFactory;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;
use Symfony\Component\Console\Output\ConsoleOutput;

class IndexManagement {
    /** @var StateInterfaceFactory $stateFactory */
    private $stateFactory;
    /** @var ConfigInterface $indexerConfig */
    private $indexerConfig;
    /** @var IndexerRegistry $indexerRegistry */
    private $indexerRegistry;
    /** @var ActionFactory $indexActionFactory */
    private $indexActionFactory;

    /** @var Data $helper */
    private $helper;
    /** @var ConsoleOutput $output */
    private $output;
    /** @var Logger $logger */
    private $logger;

    public function __construct(
        StateInterfaceFactory $stateFactory,
        ConfigInterface $indexerConfig,
        IndexerRegistry $indexerRegistry,
        ActionFactory $indexActionFactory,
        Data $helper,
        ConsoleOutput $output,
        Logger $logger
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
    private function waitForIndexCompletion()
    {
        $waitStart = \time();
        while(!$this->noIndexersRunning()) {
            sleep(5);
            $now = \time();
            if($now - $waitStart > 1800) {
                $this->print("Waited 30 minutes for index completion, abandoning...");
                break;
            }
        }
    }

    private function print($message)
    {
        $this->output->writeln($message);
        $this->logger->info($message);
    }

    /**
     * Invalidate the index with the given name
     * @param string $indexerName
     * @return void
     */
    public function invalidateIndex(string $indexerName)
    {
        $indexer = $this->indexerRegistry->get($indexerName);
        $indexer->invalidate();
    }

    /**
     * Run a full reindex of the index with the given name
     * @param string $indexerName
     * @return void
     */
    public function runIndex(string $indexerName)
    {
        //Only actually run the index if "Indexing separately" is off and the current import is not a full import
        // (as the full import runs a full reindex at the end anyway and its just a waste of time to do the index twice)
        if (!$this->helper->indexSeparately() && $this->helper->currentImportType() != 'FULL') {
            $indexer = $this->indexerRegistry->get($indexerName);
            $indexActions = $this->indexActionFactory->create($indexer->getActionClass());
            $indexActions->executeFull();
        }
    }
}