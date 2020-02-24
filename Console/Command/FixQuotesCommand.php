<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FixQuotesCommand extends Command
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
        $this->setName('sinch:fix-quotes');
        $this->setDescription('Fixes quote items so they correspond to existing products again after having changed ID');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->writeln("Fixing quote items");
            $this->_appState->setAreaCode('adminhtml');
            $this->eventManager->dispatch('sinchimport_fix_quote_items');
            $output->writeln("Ran fix without error, see log for more details");
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return \Magento\Framework\Console\Cli::RETURN_FAILURE;
    }
}
