<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Indexer\Config\DependencyInfoProvider;
use Magento\Framework\Indexer\IndexerRegistry;
use SITC\Sinchimport\Helper\Data;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This class prevents the indexers from being manually reindexed from command line while an import is running.
 */
class IndexerReindexCommand extends \Magento\Indexer\Console\Command\IndexerReindexCommand {
    /** @var Data $helper */
    private $helper;


    public function __construct(
        ObjectManagerFactory $objectManagerFactory,
        IndexerRegistry $indexerRegistry = null,
        DependencyInfoProvider $dependencyInfoProvider = null,
        Data $helper
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
     * @return int 0 if everything went fine, or an error code
     * @throws LogicException when the index lock is held
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if($this->helper->isIndexLockHeld()){
            $output->writeln("Reindexing is disabled while the Sinchimport index lock is held");
            return Cli::RETURN_FAILURE;
        }
        return parent::execute($input, $output);
    }
}
