<?php

namespace SITC\Sinchimport\Console\Command;

use Exception;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use SITC\Sinchimport\Model\Import\IndexManagement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TonerFinderCommand extends Command {
    public function __construct(
        protected readonly AppState $appState,
        protected readonly IndexManagement $indexManagement,
    ) {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->setName('sinch:tonerfinder')
            ->setDescription('Create product part finder multi-store.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $returnValue = Cli::RETURN_FAILURE;
        try {
            $this->appState->setAreaCode('adminhtml');
            $this->indexManagement->insertCategoryIdForFinder();
            $this->indexManagement->clearCaches();
            $output->writeln('Setup for Product part finder multi-store completed successfully.');
            $returnValue = Cli::RETURN_SUCCESS;
        } catch (Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $returnValue;
    }
}
