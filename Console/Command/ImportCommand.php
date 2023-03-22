<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->_appState->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
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
                $output->writeln("<error>Unknown import type '{$importType}', select one of: 'full', 'stockprice'</error>");
                return Cli::RETURN_FAILURE;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }
}
