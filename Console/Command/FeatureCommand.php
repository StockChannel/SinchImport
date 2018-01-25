<?php

namespace SITC\Sinchimport\Console\Command;

use Magento\Framework\App\State as AppState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FeatureCommand extends Command
{
    const INPUT_KEY_FEATURE_ACTION = 'feature_action';
    
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
        $this->setName('sinch:feature');
        $this->setDescription('Update Product Features');
        
        $this->addArgument(
            self::INPUT_KEY_FEATURE_ACTION,
            InputArgument::REQUIRED,
            'Feature Action'
        );
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_appState->setAreaCode('adminhtml');
        $importType = $input->getArgument(self::INPUT_KEY_FEATURE_ACTION);
        
        try {
            switch (strtolower($importType)) {
            case 'clean':
                $this->sinch->dropFeatureResultTables();
                break;
            default:
                $this->sinch->dropFeatureResultTables();
                break;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            
            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }
    }
}
