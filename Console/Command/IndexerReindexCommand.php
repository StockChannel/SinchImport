<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This class prevents the indexers from being manually reindexed from command line while an import is running.
 */
class IndexerReindexCommand extends \Magento\Indexer\Console\Command\IndexerReindexCommand {
    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;


    public function __construct(
        \Magento\Framework\App\ObjectManagerFactory $objectManagerFactory,
        \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry = null,
        \Magento\Framework\Indexer\Config\DependencyInfoProvider $dependencyInfoProvider = null,
        \SITC\Sinchimport\Helper\Data $helper
    ){
        parent::__construct($objectManagerFactory, $indexerRegistry, $dependencyInfoProvider);
        $this->helper = $helper;
    }

    /**
     * Override the execute method to prevent indexes from running when the index lock is held
     * 
     * @param InputInterface $input The input interface to pass to the original function
     * @param OutputInterface $output The output interface to pass to the original function
     * 
     * @return int|null null or 0 if everything went fine, or an error code
     * @throws LogicException when the index lock is held
     */
    protected function execute(InputInterface $input, OutputInterface $output):int{
        if($this->helper->isIndexLockHeld()){
            $output->writeln("Reindexing is disabled while the Sinchimport index lock is held");
            return Cli::RETURN_FAILURE;
        }
        return parent::execute($input, $output);
    }
}