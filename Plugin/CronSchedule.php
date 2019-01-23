<?php
namespace SITC\Sinchimport\Plugin;

class CronSchedule {
    /**
     * @var \Magento\Cron\Model\ConfigInterface
     */
    private $cronConfig;

    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    private $dir;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConn;

    public function __construct(
        \Magento\Cron\Model\ConfigInterface $cronConfig,
        \Magento\Framework\Filesystem\DirectoryList $dir,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger
    ){
        $this->cronConfig = $cronConfig;
        $this->dir = $dir;
        $this->resourceConn = $resourceConn;
        $this->logger = $logger;
    }

    public function aroundTryLockJob(\Magento\Cron\Model\Schedule $subject, $proceed){
        $cronjob = $this->getJobConfig($subject->getJobCode());

        if (isset($cronjob['group']) AND $cronjob['group'] === 'index') {
            //Manual lock indexing flag (for testing/holding the indexers for other reasons)
            if (file_exists($this->dir->getPath("var") . "/sinch_lock_indexers.flag")) {
                $this->logger->info("Preventing task {$cronjob['name']} from running as the lock indexing flag exists");
                return false;
            }

            //Import lock
            $is_lock_free = $this->resourceConn->getConnection()->fetchOne("SELECT IS_FREE_LOCK('sinchimport')");
            if ($is_lock_free === '0') {
                $this->logger->info("Preventing task {$cronjob['name']} from running as the sinchimport lock is currently held");
                return false;
            }
        }

        return $proceed();
    }

    private function getJobConfig($jobCode)
    {
        foreach ($this->cronConfig->getJobs() as $jobGroupCode => $jobGroup) {
            foreach ($jobGroup as $job) {
                if ($job['name'] == $jobCode) {
                    $job['group'] = $jobGroupCode;
                    return $job;
                }
            }
        }

        $this->logger->warning("Failed to establish group for cronjob: {$jobCode}");
        return [];
    }
}