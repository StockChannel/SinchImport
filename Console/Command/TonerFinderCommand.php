<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TonerFinderCommand extends Command {

    protected $sinch;
    protected $appState;

    public function __construct(
        AppState $appState,
        \SITC\Sinchimport\Model\Sinch $sinch
    ) {
        parent::__construct();
        $this->appState  = $appState;
        $this->sinch     = $sinch;
    }


    protected function configure()
    {
        $this->setName('sinch:tonerfinder')
            ->setDescription('Create product part finder multi-store.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $returnValue = \Magento\Framework\Console\Cli::RETURN_FAILURE;
        try {
            $this->appState->setAreaCode('adminhtml');
            $this->sinch->insertCategoryIdForFinder();
            $this->sinch->runCleanCache();
            $output->writeln('Setup for Product part finder multi-store completed successfully.');
            $returnValue = \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
        }
        return $returnValue;
    }
}