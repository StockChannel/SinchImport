<?php
namespace SITC\Sinchimport\Model;


use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Output\OutputInterface;

class CronCleanup
{
    /** @var ResourceConnection */
    private $resourceConn;

    public function __construct(
        ResourceConnection $resourceConn
    ){
        $this->resourceConn = $resourceConn;
    }

    public function cleanup(OutputInterface $output)
    {
        $cronSchedule = $this->resourceConn->getTableName('cron_schedule');
        $conn = $this->resourceConn->getConnection();

        $output->writeln("Making sure that jobs scheduled more than 2 hours ago and still pending get marked 'missed'");
        $conn->query("UPDATE $cronSchedule SET status = 'missed' WHERE status = 'pending' AND scheduled_at < NOW() - INTERVAL 2 HOUR");

        $output->writeln("Marking any jobs which started running more than 4 hours ago as failed");
        $conn->query("UPDATE $cronSchedule SET status = 'failed' WHERE status = 'running' AND executed_at < NOW() - INTERVAL 4 HOUR");

        $output->writeln("Deleting any missed, failed or errored jobs more than a day old");
        $conn->query("DELETE FROM $cronSchedule WHERE status IN ('missed', 'failed', 'error') AND scheduled_at < NOW() - INTERVAL 1 DAY");
    }
}