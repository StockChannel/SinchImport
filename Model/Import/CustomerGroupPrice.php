<?php

namespace SITC\Sinchimport\Model\Import;

/**
 * Class CustomerGroupPrice
 * @package SITC\Sinchimport\Model\Import
 */
class CustomerGroupPrice {

    const CUSTOMER_GROUPS = 'group_name';

    /**
     * CSV parser
     * @var \Magento\Framework\File\Csv
     */
    private $csv;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $_resource;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

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
        $this->catalogProductEntity = $this->connection->getTableName('catalog_product_entity');

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
                'group_id'   => $groupData[0],
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
            $this->connection->query(
                "DROP TABLE IF EXISTS sinch_customer_group_price_tmp"
            );
            $this->connection->query(
                "CREATE TABLE `sinch_customer_group_price_tmp` (
                      `group_id` int(10) UNSIGNED NOT NULL COMMENT 'Group Id',
                      `sinch_product_id` int(10) UNSIGNED NOT NULL COMMENT 'Product Id',
                      `customer_group_price` decimal(12,4) NOT NULL DEFAULT '0.0000' COMMENT 'Customer Group Price'
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Sinch Customer Group Price';"
            );


            $customerGroupPriceData[] = [
                'group_id'             => $priceData[0],
                'sinch_product_id'     => $priceData[1],
                'customer_group_price' => $priceData[2],
            ];

            $this->connection->insertOnDuplicate(
                'sinch_customer_group_price_tmp', $customerGroupPriceData
            );

            $this->connection->query("TRUNCATE TABLE " . $this->customerGroupPrice);

            $this->connection->query("
                INSERT INTO {$this->customerGroupPrice} (
                                    product_id,
                                    sinch_product_id
                                )
                                  SELECT
                                    a.entity_id,
                                    b.sinch_product_id
                                  FROM {$this->catalogProductEntity} a
                                  INNER JOIN sinch_customer_group_price_tmp b
                                    ON a.sinch_product_id = b.sinch_product_id
                                    ON DUPLICATE KEY UPDATE
                                    product_id= a.entity_id,
                                    sinch_product_id=b.sinch_product_id
            ");

            $this->connection->query("
                UPDATE {$this->customerGroupPrice} ccpfd
                JOIN sinch_customer_group_price_tmp p
                    ON ccpfd.sinch_product_id = p.sinch_product_id
                SET ccpfd.group_id = p.group_id , ccpfd.customer_group_price = p.customer_group_price
                WHERE ccpfd.sinch_product_id = p.sinch_product_id
            ");
        }

        $this->connection->query(
            "DROP TABLE IF EXISTS sinch_customer_group_price_tmp"
        );

//        $this->saveCustomerGroupPriceFinish($customerGroupPriceData, $this->customerGroupPrice);
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
     * @return float
     */
    private function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

}