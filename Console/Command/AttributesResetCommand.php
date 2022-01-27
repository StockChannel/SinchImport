<?php

namespace SITC\Sinchimport\Console\Command;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Console\Cli;
use SITC\Sinchimport\Model\Import\Attributes;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributesResetCommand extends Command
{
    /** @var ResourceConnection */
    private $resourceConn;
    
    public function __construct(ResourceConnection $resourceConn) {
        parent::__construct();
        $this->resourceConn = $resourceConn;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sinch:attributes:reset');
        $this->setDescription('Resets sinch attributes (category filters), ready for a complete reimport');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->write("Resetting attributes...");
            $eavAttr = $this->resourceConn->getTableName('eav_attribute');
            $conn = $this->resourceConn->getConnection()->query("DELETE FROM {$eavAttr} WHERE attribute_code LIKE '" . Attributes::ATTRIBUTE_PREFIX . "%'");
            $output->writeln("done");
            return Cli::RETURN_SUCCESS;
        } catch (Exception $e) {
            $output->writeln("failed");
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return Cli::RETURN_FAILURE;
    }
}
