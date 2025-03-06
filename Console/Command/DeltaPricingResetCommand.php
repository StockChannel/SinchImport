<?php

namespace SITC\Sinchimport\Console\Command;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use SITC\Sinchimport\Model\Import\AccountGroupPrice;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeltaPricingResetCommand extends Command
{
    private ResourceConnection $resourceConn;
    
    public function __construct(ResourceConnection $resourceConn) {
        parent::__construct();
        $this->resourceConn = $resourceConn;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setName('sinch:delta-pricing:reset');
        $this->setDescription('Resets delta pricing, causing tier prices to be cleared and rebuilt from scratch upon the next import');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $output->write("Resetting delta pricing...");
            $currTable = $this->resourceConn->getTableName(AccountGroupPrice::PRICE_TABLE_CURRENT);
            $nextTable = $this->resourceConn->getTableName(AccountGroupPrice::PRICE_TABLE_NEXT);
            $conn = $this->resourceConn->getConnection();
            $conn->query(
                "DROP TABLE IF EXISTS {$currTable}"
            );
            $conn->query(
                "DROP TABLE IF EXISTS {$nextTable}"
            );
            $output->writeln("done");
            return Cli::RETURN_SUCCESS;
        } catch (Exception $e) {
            $output->writeln("failed");
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return Cli::RETURN_FAILURE;
    }
}
