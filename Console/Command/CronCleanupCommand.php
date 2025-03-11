<?php

namespace SITC\Sinchimport\Console\Command;

use Exception;
use Magento\Framework\Console\Cli;
use SITC\Sinchimport\Model\CronCleanup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CronCleanupCommand extends Command
{
    private CronCleanup $cleaner;
    
    public function __construct(CronCleanup $cleaner) {
        parent::__construct();
        $this->cleaner = $cleaner;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setName('sinch:cron:cleanup');
        $this->setDescription('Clean up the Magento cron schedule table, resolving potential issues with the running of cron tasks');
    }
    
    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->cleaner->cleanup($output);
            return Cli::RETURN_SUCCESS;
        } catch (Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
        }
        return Cli::RETURN_FAILURE;
    }
}
