<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\File\Csv;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;
use Symfony\Component\Console\Output\ConsoleOutput;

class AccountGroupCategories extends AbstractImportSection {
    const LOG_PREFIX = "AccountGroupCategories: ";
    const LOG_FILENAME = "account_group_cats";

    const MAPPING_TABLE = "sinch_cat_visibility";

    /**
     * @var Csv
     */
    private $csv;

    //Holds the mapped table name
    private $mappingTablenameFinal;
    //Holds the prepared SQL statement for inserting mapping rows
    private $insertMapping = null;

    public function __construct(
        ResourceConnection $resourceConn,
        ConsoleOutput $output,
        Download $dlHelper,
        Csv $csv
    ){
        parent::__construct($resourceConn, $output, $dlHelper);
        $this->csv = $csv->setLineLength(256)->setDelimiter(Sinch::FIELD_TERMINATED_CHAR);
        $this->mappingTablenameFinal = $this->getTableName(self::MAPPING_TABLE);
    }

    public function getRequiredFiles(): array
    {
        return [
            Download::FILE_ACCOUNT_GROUP_CATEGORIES
        ];
    }

    /**
     * Parses the customer group categories file into the mapping table
     */
    public function parse()
    {
        $accountGroupCatsFile = $this->dlHelper->getSavePath(Download::FILE_ACCOUNT_GROUP_CATEGORIES);

        $this->log("--- Begin Account Group Categories Parse ---");
        $customerGroupCats = $this->csv->getData($accountGroupCatsFile);
        unset($customerGroupCats[0]); //Unset the first entry as the sinch export files have a header row

        $this->log("Deleting existing entries in customer group categories mapping table");
        $this->getConnection()->query("DELETE FROM {$this->mappingTablenameFinal}");

        //Parse customer group categories
        $this->log("Begin parsing new entries (" . count($customerGroupCats) . ")");
        foreach($customerGroupCats as $row){
            if(count($row) != 2) {
                $this->logger->warn("AccountGroupCategories row not 2 columns");
                $this->logger->debug(print_r($row, true));
                continue;
            }

            $this->insertMapping($row[0], $row[1]);
        }

        $this->log("--- Completed Account Group Categories parse ---");
    }

    private function insertMapping($account_group_id, $category_id)
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