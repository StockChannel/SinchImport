<?php

namespace SITC\Sinchimport\Model\Import;

/**
 * Class CustomerGroupPrice
 * @package SITC\Sinchimport\Model\Import
 */
class CustomerGroupPrice {

    const CUSTOMER_GROUPS = 'group_name';
    const PRICE_COLUMN = 'customer_group_price';
    const CHUNK_SIZE = 1000;
    const LOG_PREFIX = "CustomerGroupPrice: ";

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

    private $customerGroupCount = 0;
    private $customerGroupPriceCount = 0;

    /**
     * @var string Customer Group table
     */
    private $customerGroup;
    /**
     * @var string Customer Group Price table
     */
    private $customerGroupPrice;
    /**
     * @var string Customer Group Price temporary table
     */
    private $tmpTable;
    /**
     * @var string Catalog Product Entity table
     */
    private $catalogProductEntity;

    /**
     * CustomerGroupPrice constructor.
     * @param \SITC\Sinchimport\Util\CsvIterator $csv
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Symfony\Component\Console\Output\ConsoleOutput $output
     */
    public function __construct(
        \SITC\Sinchimport\Util\CsvIterator $csv,
        \Magento\Framework\App\ResourceConnection $resource,
        \Symfony\Component\Console\Output\ConsoleOutput $output
    ){
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        $this->connection = $resource->getConnection();
        $this->customerGroup = $resource->getTableName('sinch_customer_group');
        $this->customerGroupPrice = $resource->getTableName('sinch_customer_group_price');
        $this->tmpTable = $resource->getTableName('sinch_customer_group_price_tmp');
        $this->catalogProductEntity = $resource->getTableName('catalog_product_entity');

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/sinch_customer_groups_price.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $this->logger = $logger;
        $this->output = $output;
    }


    /**
     * @param string $customerGroupFile
     * @param string $customerGroupPriceFile
     * @throws \Exception
     */
    public function parse($customerGroupFile, $customerGroupPriceFile)
    {
        $parseStart = $this->microtime_float();

        $customerGroupCsv = $this->csv->getData($customerGroupFile);
        unset($customerGroupCsv[0]);

        $this->csv->openIter($customerGroupPriceFile);
        $this->csv->take(1); //Discard first row


        //Prepare customer group data for insertion
        $customerGroupData = [];
        foreach($customerGroupCsv as $groupData){
            $this->customerGroupCount += 1;
            $customerGroupData[] = [
                'group_id'   => $groupData[0],
                'group_name' => $groupData[1],
            ];
        }

        //Delete existing customer groups
        $this->connection->query("DELETE FROM {$this->customerGroup}");

        if(count($customerGroupData) > 0) {
            //Insert new customer groups
            $this->connection->insertOnDuplicate(
                $this->customerGroup,
                $customerGroupData,
                [self::CUSTOMER_GROUPS]
            );
        }

        $elapsed = $this->microtime_float() - $parseStart;
        $this->log("Processed {$this->customerGroupCount} customer groups in {$elapsed} seconds");

        //Drop (if necessary) and recreate tmp table
        $this->connection->query("DROP TABLE IF EXISTS {$this->tmpTable}");
        $this->connection->query(
            "CREATE TABLE `{$this->tmpTable}` (
                `group_id` int(10) UNSIGNED NOT NULL COMMENT 'Group Id',
                `sinch_product_id` int(10) UNSIGNED NOT NULL COMMENT 'Sinch Product Id',
                `price_type_id` int(10) UNSIGNED NOT NULL COMMENT 'Price Type Id',
                `customer_group_price` decimal(12,4) NOT NULL DEFAULT '0.0000' COMMENT 'Customer Group Price',
                UNIQUE KEY (`group_id`, `sinch_product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Sinch Customer Group Price Temp';"
        );

        $loopNum = 0;
        while($toProcess = $this->csv->take(self::CHUNK_SIZE)) {
            //Process price records ready for insertion
            $customerGroupPriceData = [];
            foreach($toProcess as $priceData){
                $this->customerGroupPriceCount += 1;
                $customerGroupPriceData[] = [
                    'group_id'             => $priceData[0],
                    'sinch_product_id'     => $priceData[1],
                    'price_type_id'        => $priceData[2],
                    'customer_group_price' => $priceData[3],
                ];
            }

            //Insert the price records into the temp table ready for mapping
            $this->connection->insertOnDuplicate(
                $this->tmpTable,
                $customerGroupPriceData,
                [self::PRICE_COLUMN]
            );
            $loopNum += 1;
            $numRecords = count($customerGroupPriceData);
            $this->log("Inserted {$numRecords} records ready for processing (Iteration #{$loopNum})");
        }
        $this->csv->closeIter();

        //Delete existing price records from the live table
        $this->connection->query("DELETE FROM {$this->customerGroupPrice}");

        //Perform the mapping into the live table
        $this->connection->query(
            "INSERT INTO {$this->customerGroupPrice} (
                group_id,
                price_type_id,
                sinch_product_id,
                customer_group_price,
                product_id
            )
            SELECT 
                tmp.group_id,
                tmp.price_type_id,
                tmp.sinch_product_id,
                tmp.customer_group_price,
                cpe.entity_id
            FROM {$this->tmpTable} tmp
            INNER JOIN {$this->catalogProductEntity} cpe
                ON tmp.sinch_product_id = cpe.sinch_product_id
            ON DUPLICATE KEY UPDATE
                price_type_id = tmp.price_type_id,
                customer_group_price = tmp.customer_group_price"
        );

        //Drop the tmp table as its no longer needed
        $this->connection->query("DROP TABLE {$this->tmpTable}");

        $elapsed = $this->microtime_float() - $parseStart;
        $this->log("Processed {$this->customerGroupPriceCount} group prices in {$elapsed} seconds");
    }

    /**
     * @return float
     */
    private function microtime_float()
    {
        list($usec, $sec) = explode(" ", microtime());
        return ((float)$usec + (float)$sec);
    }

    private function log($msg)
    {
        $this->output->writeln(self::LOG_PREFIX . $msg);
        $this->logger->info(self::LOG_PREFIX . $msg);
    }
}