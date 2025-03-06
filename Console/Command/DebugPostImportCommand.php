<?php

namespace SITC\Sinchimport\Console\Command;

use Exception;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Magento\Framework\Event\ManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugPostImportCommand extends Command
{
    protected AppState $_appState;
    protected ManagerInterface $eventManager;
    
    
    public function __construct(
        AppState $appState,
        ManagerInterface $eventManager
    ) {
        parent::__construct();
        $this->_appState = $appState;
        $this->eventManager = $eventManager;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setName('sinch:debug:post-import');
        $this->setDescription('Runs post import handlers');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->writeln("Dispatching post-import event");
            $this->_appState->setAreaCode('adminhtml');
            $this->eventManager->dispatch(
                'sinchimport_post_import',
                [
                    'import_type' => 'PRICE STOCK'
                ]
            );
            $output->writeln("Ran post import hooks without error, see log for more details");
            return Cli::RETURN_SUCCESS;
        } catch (Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return Cli::RETURN_FAILURE;
    }
}
