<?php
namespace SITC\Sinchimport\Model\Import;

class CustomerGroupCategories {

    const MAPPING_TABLE = "sinch_cat_visibility";

    /**
     * @var \Magento\Framework\File\Csv
     */
    private $csv;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resourceConn;

    /**
     * @var \SITC\Sinchimport\Logger\Logger
     */
    private $logger;

    //Holds the mapped table name
    private $mappingTablenameFinal;
    //Holds the prepared SQL statement for inserting mapping rows
    private $insertMapping = null;

    public function __construct(
        \Magento\Framework\File\Csv $csv,
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \SITC\Sinchimport\Logger\Logger $logger
    )
    {
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        $this->resourceConn = $resourceConn;
        $this->logger = $logger;
        $this->mappingTablenameFinal = $this->resourceConn->getTableName(self::MAPPING_TABLE);
    }

    /**
     * Parses the customer group categories file into the mapping table
     *
     * @param string $customerGroupCatsFile The path to the CustomerGroupCategories.csv file
     */
    public function parse($customerGroupCatsFile)
    {
        $this->logger->info("--- Begin Customer Group Categories Parse ---");
        $customerGroupCats = $this->csv->getData($customerGroupCatsFile);
        unset($customerGroupCats[0]); //Unset the first entry as the sinch export files have a header row

        $this->logger->info("Deleting existing entries in customer group categories mapping table");
        $this->getConnection()->query("DELETE FROM {$this->mappingTablenameFinal}");

        //Parse customer group categories
        $this->logger->info("Begin parsing new entries (" . count($customerGroupCats) . ")");
        foreach($customerGroupCats as $row){
            if(count($row) != 2) {
                $this->logger->warn("CustomerGroupCategories row not 2 columns");
                $this->logger->debug(print_r($row, true));
                continue;
            }

            $this->insertMapping($row[0], $row[1]);
        }

        $this->logger->info("--- Completed Customer Group Categories parse ---");
    }

    private function insertMapping($category_id, $account_group_id)
    {
        if (empty($this->insertMapping)) {
            $this->insertMapping = $this->getConnection()->prepare(
                "INSERT INTO {$this->mappingTablenameFinal} (category_id, account_group_id) VALUES(:category_id, :account_group_id)"
            );
        }

        $this->insertMapping->bindValue(":category_id", $category_id, \PDO::PARAM_INT);
        $this->insertMapping->bindValue(":account_group_id", $account_group_id, \PDO::PARAM_INT);
        $this->insertMapping->execute();
        $this->insertMapping->closeCursor();
    }


    private function getConnection()
    {
        return $this->resourceConn->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
    }
}