<?php

namespace SITC\Sinchimport\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeltaPricingResetCommand extends Command
{
    /** @var \Magento\Framework\App\ResourceConnection */
    private $resourceConn;
    
    public function __construct(\Magento\Framework\App\ResourceConnection $resourceConn) {
        parent::__construct();
        $this->resourceConn = $resourceConn;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sinch:delta-pricing:reset');
        $this->setDescription('Resets delta pricing, causing tier prices to be cleared and rebuilt from scratch upon the next import');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->write("Resetting delta pricing...");
            $currTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupPrice::PRICE_TABLE_CURRENT);
            $nextTable = $this->resourceConn->getTableName(\SITC\Sinchimport\Model\Import\CustomerGroupPrice::PRICE_TABLE_NEXT);
            $conn = $this->resourceConn->getConnection();
            $conn->query(
                "DROP TABLE IF EXISTS {$currTable}"
            );
            $conn->query(
                "DROP TABLE IF EXISTS {$nextTable}"
            );
            $output->writeln("done");
            return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("failed");
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return \Magento\Framework\Console\Cli::RETURN_FAILURE;
    }
}
