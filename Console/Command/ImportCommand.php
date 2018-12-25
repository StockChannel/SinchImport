<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ImportCommand
 * @package SITC\Sinchimport\Console\Command
 */
class ImportCommand extends Command
{
    const INPUT_KEY_IMPORT_TYPE = 'import_type';
    
    /**
     * @var AppState
     */
    protected $_appState;
    
    /**
     * @var \SITC\Sinchimport\Model\Sinch
     */
    protected $sinch;

    /**
     * ImportCommand constructor.
     * @param AppState $appState
     * @param \SITC\Sinchimport\Model\Sinch $sinch
     */
    public function __construct(
        AppState $appState,
        \SITC\Sinchimport\Model\Sinch $sinch
    ) {
        $this->_appState = $appState;
        $this->sinch     = $sinch;
        parent::__construct();
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sinch:import');
        $this->setDescription(
            'Import Products from Stock In The Channel Server'
        );
        
        $this->addArgument(
            self::INPUT_KEY_IMPORT_TYPE,
            InputArgument::REQUIRED,
            'Import Type'
        );
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_appState->setAreaCode('adminhtml');
        $importType = $input->getArgument(self::INPUT_KEY_IMPORT_TYPE);
        
        try {
            switch (strtolower($importType)) {
            case 'full':
                $this->sinch->runSinchImport();
                break;
            case 'stockprice':
                $this->sinch->runStockPriceImport();
                break;
            default:
                $this->sinch->runSinchImport();
                break;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}
