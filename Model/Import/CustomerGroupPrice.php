<?php

namespace SITC\Sinchimport\Model\Import;

/**
 * Class CustomerGroupPrice
 * @package SITC\Sinchimport\Model\Import
 */
class CustomerGroupPrice {

    const CUSTOMER_GROUPS = 'group_name';

    const CUSTOMER_GROUPS_PRICE = 'customer_group_price';

    /**
     * CSV parser
     * @var \Magento\Framework\File\Csv
     */
    private $csv;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;

    /**
     * @var \Zend\Log\Logger
     */
    private $logger;

    /**
     * @var \Symfony\Component\Console\Output\ConsoleOutput
     */
    private $output;

    /**
     * @var int
     */
    private $customerGroupCount = 0;

    /**
     * @var int
     */
    private $customerGroupPriceCount = 0;

    /**
     * CustomerGroupPrice constructor.
     * @param \Magento\Framework\File\Csv $csv
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Symfony\Component\Console\Output\ConsoleOutput $output
     */
    public function __construct(
        \Magento\Framework\File\Csv $csv,
        \Magento\Framework\App\ResourceConnection $resource,
        \Symfony\Component\Console\Output\ConsoleOutput $output
    ){
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        $this->connection         = $resource->getConnection();
        $this->customerGroup      = $this->connection->getTableName('sinch_customer_group');
        $this->customerGroupPrice = $this->connection->getTableName('sinch_customer_group_price');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/customer_groups_price.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
        $this->output = $output;
    }


    /**
     * @param $customerGroup
     * @param $customerGroupPrice
     * @throws \Exception
     */
    public function parse($customerGroup, $customerGroupPrice)
    {
        $parseStart = $this->microtime_float();

        $customerGroupCsv = $this->csv->getData($customerGroup);
        unset($customerGroupCsv[0]);

        $customerGroupPriceCsv = $this->csv->getData($customerGroupPrice);
        unset($customerGroupPriceCsv[0]);

        //Save data file customerGroups.csv
        $customerGroupData = [];
        foreach($customerGroupCsv as $groupData){
            $this->customerGroupCount += 1;
            $customerGroupData[] = [
                'group_id'  => $groupData[0],
                'group_name' => $groupData[1],
            ];
        }

        $this->saveCustomerGroupFinish($customerGroupData, $this->customerGroup);
        $elapsed = $this->microtime_float() - $parseStart;

        $this->output->writeln("Processed a groups of " . $this->customerGroupCount . " and time is " . $elapsed . " seconds");
        $this->logger->info("Processed a groups price of " . $this->customerGroupCount . " and time is " . $elapsed . " seconds");


        //Save data file customerGroupPrice.csv
        $customerGroupPriceData = [];
        foreach($customerGroupPriceCsv as $priceData){

            $this->customerGroupPriceCount += 1;

            $customerGroupPriceData[] = [
                'group_id'   => $priceData[0],
                'product_id' => $priceData[1],
                'customer_group_price' => $priceData[2],
            ];
        }

        $this->saveCustomerGroupPriceFinish($customerGroupPriceData, $this->customerGroupPrice);
        $elapsed = $this->microtime_float() - $parseStart;

        $this->output->writeln("Processed a group price of " . $this->customerGroupPriceCount . " and time is " . $elapsed . " seconds");
        $this->logger->info("Processed a group price of " . $this->customerGroupPriceCount . " and time is " . $elapsed . " seconds");
    }

    /**
     * @param array $entityData
     * @param $table
     * @return $this
     */
    private function saveCustomerGroupFinish(array $groupData, $table)
    {
        if ($groupData) {
            $tableName = $this->connection->getTableName($table);
            $groupIn = [];
            foreach ($groupData as $id => $groupRows) {
                $groupIn[] = $groupRows;
            }
            if ($groupIn) {
                $this->connection->query("TRUNCATE TABLE " . $table);
                $this->connection->insertOnDuplicate($tableName, $groupIn, [self::CUSTOMER_GROUPS]);
            }
        }
        return $this;
    }

    /**
     * @param array $groupPriceData
     * @param $table
     * @return $this
     */
    private function saveCustomerGroupPriceFinish(array $groupPriceData, $table)
    {
        if ($groupPriceData) {
            $tableName = $this->connection->getTableName($table);
            $groupIn = [];
            foreach ($groupPriceData as $id => $groupRows) {
                $groupIn[] = $groupRows;
            }
            if ($groupIn) {
                $this->connection->query("TRUNCATE TABLE " . $table);
                $this->connection->insertOnDuplicate($tableName, $groupIn, [self::CUSTOMER_GROUPS_PRICE]);
            }
        }
        return $this;
    }

    /**
     * @return float
     */
    private function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

}