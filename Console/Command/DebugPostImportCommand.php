<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DebugPostImportCommand extends Command
{
    /**
     * @var AppState
     */
    protected $_appState;
    
    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;
    
    
    public function __construct(
        AppState $appState,
        \Magento\Framework\Event\ManagerInterface $eventManager
    ) {
        parent::__construct();
        $this->_appState = $appState;
        $this->eventManager = $eventManager;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sinch:debug:post-import');
        $this->setDescription('Runs post import handlers');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
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
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return \Magento\Framework\Console\Cli::RETURN_FAILURE;
    }
}
