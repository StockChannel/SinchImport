<?php

namespace SITC\Sinchimport\Console\Command;

use Exception;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use SITC\Sinchimport\Model\Sinch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    /**
     * @var AppState
     */
    protected $_appState;
    
    /**
     * @var Sinch
     */
    protected $sinch;
    
    
    public function __construct(
        AppState $appState,
        Sinch $sinch
    ) {
        $this->_appState = $appState;
        $this->sinch     = $sinch;
        parent::__construct();
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setName('sinch:url:generate');
        $this->setDescription('Regenerate Product and Category Urls');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->_appState->setAreaCode('adminhtml');
            $this->sinch->runReindexUrlRewrite();
        } catch (Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return Cli::RETURN_FAILURE;
    }
}
