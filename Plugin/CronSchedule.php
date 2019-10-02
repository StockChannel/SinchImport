<?php
namespace SITC\Sinchimport\Plugin;

class CronSchedule {
    /** @var \Magento\Cron\Model\ConfigInterface */
    private $cronConfig;
    /** @var \SITC\Sinchimport\Logger\Logger $logger */
    private $logger;
    /** @var \SITC\Sinchimport\Helper\Data $helper */
    private $helper;

    public function __construct(
        \Magento\Cron\Model\ConfigInterface $cronConfig,
        \SITC\Sinchimport\Logger\Logger $logger,
        \SITC\Sinchimport\Helper\Data $helper
    ){
        $this->cronConfig = $cronConfig;
        $this->logger = $logger;
        $this->helper = $helper;
    }

    public function aroundTryLockJob(\Magento\Cron\Model\Schedule $subject, $proceed){
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