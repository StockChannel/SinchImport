<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GenerateCommand
 * @package SITC\Sinchimport\Console\Command
 */
class GenerateCommand extends Command
{
    /**
     * @var AppState
     */
    protected $_appState;

    /**
     * @var \SITC\Sinchimport\Model\Sinch
     */
    protected $sinch;

    /**
     * GenerateCommand constructor.
     * @param AppState $appState
     * @param \SITC\Sinchimport\Model\Sinch $sinch
     */
    public function __construct(
        AppState $appState,
        \SITC\Sinchimport\Model\Sinch $sinch
    ) {
        $this->_appState = $appState;
        $this->sinch = $sinch;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sinch:url:generate');
        $this->setDescription('Product Urls');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_appState->setAreaCode('adminhtml');

        try {
            $this->sinch->runIndexingData();
            $this->sinch->runReindexUrlRewrite();
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}
