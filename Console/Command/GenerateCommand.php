<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
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
        $this->setDescription('Generate Product Urls');

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $returnValue = Cli::RETURN_FAILURE;

        try {
            $this->_appState->setAreaCode('adminhtml');

            // $this->sinch->runIndexingData();
            $this->sinch->runReindexUrlRewrite();

            $output->writeln('');
            $output->writeln('Generated url rewrites successfully!');
            $returnValue = Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage());
        }

        return $returnValue;
    }
}