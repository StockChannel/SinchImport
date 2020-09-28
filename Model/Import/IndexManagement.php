<?php

namespace SITC\Sinchimport\Model\Import;

class IndexManagement {
    /** @var \Magento\Framework\Indexer\StateInterfaceFactory $stateFactory */
    private $stateFactory;
    /** @var \Magento\Framework\Indexer\ConfigInterface $indexerConfig */
    private $indexerConfig;

    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;
    /** @var \Symfony\Component\Console\Output\ConsoleOutput $output */
    private $output;
    /** @var \SITC\Sinchimport\Logger\Logger $logger */
    private $logger;

    public function __construct(
        \Magento\Framework\Indexer\StateInterfaceFactory $stateFactory,
        \Magento\Framework\Indexer\ConfigInterface $indexerConfig,
        \SITC\Sinchimport\Helper\Data $helper,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \SITC\Sinchimport\Logger\Logger $logger
    ){
        $this->stateFactory = $stateFactory;
        $this->indexerConfig = $indexerConfig;
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
    public function ensureIndexersNotRunning()
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
    private function noIndexersRunning()
    {
        foreach(array_keys($this->indexerConfig->getIndexers()) as $indexerId) {
            $indexerState = $this->stateFactory->create();
            $indexerState->loadByIndexer($indexerId);
            if ($indexerState->getStatus() == \Magento\Framework\Indexer\StateInterface::STATUS_WORKING) {
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
}