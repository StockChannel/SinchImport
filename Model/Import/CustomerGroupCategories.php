<?php
namespace SITC\Sinchimport\Model\Import;

use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;

class CustomerGroupCategories extends AbstractImportSection {
    const LOG_PREFIX = "CustomerGroupCategories: ";
    const LOG_FILENAME = "customer_groups_cats";

    const MAPPING_TABLE = "sinch_cat_visibility";

    /**
     * @var \Magento\Framework\File\Csv
     */
    private $csv;

    //Holds the mapped table name
    private $mappingTablenameFinal;
    //Holds the prepared SQL statement for inserting mapping rows
    private $insertMapping = null;
	

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \Magento\Framework\File\Csv $csv,
	    Download $dlHelper
    ){
	    parent::__construct($resourceConn, $output, $dlHelper);
	    $this->csv = $csv->setLineLength(256)->setDelimiter(Sinch::FIELD_TERMINATED_CHAR);
        $this->mappingTablenameFinal = $this->getTableName(self::MAPPING_TABLE);
    }
	
	public function getRequiredFiles(): array
	{
		return [
		];
	}
	
    /**
     * Parses the customer group categories file into the mapping table
     *
     * @param string $customerGroupCatsFile The path to the CustomerGroupCategories.csv file
     */
    public function parse($customerGroupCatsFile)
    {
        $this->log("--- Begin Customer Group Categories Parse ---");
        $customerGroupCats = $this->csv->getData($customerGroupCatsFile);
        unset($customerGroupCats[0]); //Unset the first entry as the sinch export files have a header row

        $this->log("Deleting existing entries in customer group categories mapping table");
        $this->getConnection()->query("DELETE FROM {$this->mappingTablenameFinal}");

        //Parse customer group categories
        $this->log("Begin parsing new entries (" . count($customerGroupCats) . ")");
        foreach($customerGroupCats as $row){
            if(count($row) != 2) {
                $this->logger->warn("CustomerGroupCategories row not 2 columns");
                $this->logger->debug(print_r($row, true));
                continue;
            }

            $this->insertMapping($row[0], $row[1]);
        }

        $this->log("--- Completed Customer Group Categories parse ---");
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
}