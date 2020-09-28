<?php
namespace SITC\Sinchimport\Plugin;

use Magento\Cron\Model\ConfigInterface;
use Magento\Cron\Model\Schedule;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Logger\Logger;

class CronSchedule {
    /** @var ConfigInterface */
    private $cronConfig;
    /** @var Logger $logger */
    private $logger;
    /** @var Data $helper */
    private $helper;

    public function __construct(
        ConfigInterface $cronConfig,
        Logger $logger,
        Data $helper
    ){
        $this->cronConfig = $cronConfig;
        $this->logger = $logger->withName("CronSchedule");
        $this->helper = $helper;
    }

    public function aroundTryLockJob(Schedule $subject, $proceed){
        $cronjob = $this->getJobConfig($subject->getJobCode());

        if (isset($cronjob['group']) && $cronjob['group'] === 'index' && $this->helper->isIndexLockHeld()) {
            $this->logger->info("Preventing task {$cronjob['name']} from running as the sinchimport index lock is currently held");
            return false;
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