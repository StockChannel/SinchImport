<?php

namespace SITC\Sinchimport\Model\Import;

/**
 * Class CustomerGroupPrice
 * @package SITC\Sinchimport\Model\Import
 */
class CustomerGroupPrice extends AbstractImportSection {
    const LOG_PREFIX = "CustomerGroupPrice: ";
    const LOG_FILENAME = "customer_groups_price";

    const CUSTOMER_GROUPS = 'group_name';
    const PRICE_COLUMN = 'customer_group_price';
    const CHUNK_SIZE = 10000;

    /**
     * CSV parser
     * @var \SITC\Sinchimport\Util\CsvIterator
     */
    private $csv;

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
     * @var string Sinch_products_mapping table
     */
    private $sinchProductsMapping;

    /**
     * CustomerGroupPrice constructor.
     * @param \SITC\Sinchimport\Util\CsvIterator $csv
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Symfony\Component\Console\Output\ConsoleOutput $output
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \SITC\Sinchimport\Util\CsvIterator $csv,
    ){
        parent::__construct($resourceConn, $output);
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");

        $this->customerGroup = $this->getTableName('sinch_customer_group');
        $this->customerGroupPrice = $this->getTableName('sinch_customer_group_price');
        $this->tmpTable = $this->getTableName('sinch_customer_group_price_tmp');
        $this->sinchProductsMapping = $this->getTableName('sinch_products_mapping');
    }


    /**
     * @param string $customerGroupFile
     * @param string $customerGroupPriceFile
     * @throws \Exception
     */
    public function parse($customerGroupFile, $customerGroupPriceFile)
    {
        $this->log("Starting CustomerGroupPrice parse");
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
        $this->getConnection()->query("DELETE FROM {$this->customerGroup}");

        if(count($customerGroupData) > 0) {
            //Insert new customer groups
            $this->getConnection()->insertOnDuplicate(
                $this->customerGroup,
                $customerGroupData,
                [self::CUSTOMER_GROUPS]
            );
        }

        $elapsed = number_format($this->microtime_float() - $parseStart, 2);
        $this->log("Processed {$this->customerGroupCount} customer groups in {$elapsed} seconds");
        $parseStart = $this->microtime_float();

        //Drop (if necessary) and recreate tmp table
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->tmpTable}");
        $this->getConnection()->query(
            "CREATE TABLE `{$this->tmpTable}` (
                `group_id` int(10) UNSIGNED NOT NULL COMMENT 'Group Id',
                `sinch_product_id` int(10) UNSIGNED NOT NULL COMMENT 'Sinch Product Id',
                `price_type_id` int(10) UNSIGNED NOT NULL COMMENT 'Price Type Id',
                `customer_group_price` decimal(12,4) NOT NULL DEFAULT '0.0000' COMMENT 'Customer Group Price',
                UNIQUE KEY (`group_id`, `sinch_product_id`, `price_type_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Sinch Customer Group Price Temp';"
        );

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
            $this->getConnection()->insertOnDuplicate(
                $this->tmpTable,
                $customerGroupPriceData,
                [self::PRICE_COLUMN]
            );
        }
        $this->csv->closeIter();

        //Delete existing price records from the live table
        $this->getConnection()->query("DELETE FROM {$this->customerGroupPrice}");

        //Perform the mapping into the live table
        $this->getConnection()->query(
            "INSERT INTO {$this->customerGroupPrice} (
                group_id,
                product_id,
                price_type_id,
                customer_group_price
            )
            SELECT 
                tmp.group_id,
                spm.entity_id,
                tmp.price_type_id,
                tmp.customer_group_price
            FROM {$this->tmpTable} tmp
            INNER JOIN {$this->sinchProductsMapping} spm
                ON tmp.sinch_product_id = spm.sinch_product_id
            ON DUPLICATE KEY UPDATE
                customer_group_price = tmp.customer_group_price"
        );

        //Drop the tmp table as its no longer needed
        $this->getConnection()->query("DROP TABLE {$this->tmpTable}");

        $elapsed = $this->microtime_float() - $parseStart;
        $this->log("Processed {$this->customerGroupPriceCount} group prices in {$elapsed} seconds");
    }
}