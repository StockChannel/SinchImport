<?php

namespace SITC\Sinchimport\Model\Import;

use Magento\Catalog\Model\ResourceModel\Product\Action;
use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;
use SITC\Sinchimport\Util\CsvIterator;
use Symfony\Component\Console\Output\ConsoleOutput;

class CustomCatalogVisibility extends AbstractImportSection {
    const LOG_PREFIX = "CustomCatalog: ";
    const LOG_FILENAME = "custom_catalog";

    const CHUNK_SIZE = 1000;
    const ATTRIBUTE_NAME = "sinch_restrict";

    private string $tmpTable = "sinch_custom_catalog_tmp";
    private string $finalRulesTable = "sinch_custom_catalog_final_tmp";
    private string $flagTable = "sinch_custom_catalog_flag";

    private CsvIterator $stockPriceCsv;
    private CsvIterator $groupPriceCsv;
    private Action $massProdValues;

    private string $cpeTable;
    private string $productMappingTable;

    private int $restrictCount = 0;

    public function __construct(
        ResourceConnection $resourceConn,
        ConsoleOutput $output,
        Download $dlHelper,
        CsvIterator $csv,
        Action $massProdValues
    ){
        parent::__construct($resourceConn, $output, $dlHelper);
        $this->stockPriceCsv = $csv->setLineLength(256)->setDelimiter(Sinch::FIELD_TERMINATED_CHAR);
        $this->groupPriceCsv = clone $this->stockPriceCsv;
        $this->massProdValues = $massProdValues;

        $this->cpeTable = $this->getTableName('catalog_product_entity');
        $this->productMappingTable = $this->getTableName('sinch_products_mapping');
    }

    public function getRequiredFiles(): array
    {
        return [
            Download::FILE_STOCK_AND_PRICES,
            Download::FILE_ACCOUNT_GROUP_PRICE
        ];
    }

    private function cleanupTempTables(): void
    {
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->flagTable}");
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->tmpTable}");
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->finalRulesTable}");
    }

    private function createTempTable(): void
    {
        $this->cleanupTempTables();
        $this->getConnection()->query("SET SESSION group_concat_max_len = 102400");
        $this->getConnection()->query(
            "CREATE TABLE {$this->flagTable} (
                product_id int(11) unsigned NOT NULL PRIMARY KEY COMMENT 'Sinch Product ID',
                whitelist tinyint(1) NOT NULL COMMENT 'Is Whitelisted product',
                INDEX (whitelist)
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
        $this->getConnection()->query(
            "CREATE TABLE {$this->tmpTable} (
                rule_id int(11) unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT,
                product_id int(11) unsigned NOT NULL COMMENT 'Sinch Product ID',
                group_id int(11) unsigned NOT NULL COMMENT 'Group ID',
                INDEX (product_id)
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
        $this->getConnection()->query(
            "CREATE TABLE {$this->finalRulesTable} (
                product_id int(11) unsigned NOT NULL PRIMARY KEY COMMENT 'Product Entity ID',
                rule varchar(255) NOT NULL
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }

    public function parse(): void
    {
        $parseStart = $this->microtime_float();

        $this->createTempTable();
        $this->checkProductModes();
        $this->processGroupPrices();
        $this->buildFinalRules();
        //$this->cleanupTempTables();

        $elapsed = number_format($this->microtime_float() - $parseStart, 2);
        $this->log("Imported {$this->restrictCount} restrictions in {$elapsed} seconds");
    }

    /**
     */
    private function checkProductModes(): void
    {
        $stockPriceFile = $this->dlHelper->getSavePath(Download::FILE_STOCK_AND_PRICES);

        $this->log("Checking product modes");
        $this->stockPriceCsv->openIter($stockPriceFile);
        $this->stockPriceCsv->take(1); //Discard first row

        //ProductID|Stock|Price|Cost|DistributorID
        while($toProcess = $this->stockPriceCsv->take(self::CHUNK_SIZE)) {
            $flagsInsert = [];
            foreach($toProcess as $row) {
                //Check if Price and Cost columns are empty
                $flagsInsert[] = [
                    "product_id" => $row[0],
                    "whitelist" => $this->isEmptyOrWhitespace($row[2]) && $this->isEmptyOrWhitespace($row[3])
                ];
            }
            $this->getConnection()->insertOnDuplicate($this->flagTable, $flagsInsert);
        }
        $this->stockPriceCsv->closeIter();

        $numWhitelist = $this->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM {$this->flagTable} WHERE whitelist = 1"
        );
        $numBlacklist = $this->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM {$this->flagTable} WHERE whitelist = 0"
        );
        $this->log("{$numWhitelist} products in whitelist mode");
        $this->log("{$numBlacklist} products in blacklist mode");
    }

    /**
     */
    private function processGroupPrices(): void
    {
        $accountGroupPriceFile = $this->dlHelper->getSavePath(Download::FILE_ACCOUNT_GROUP_PRICE);

        $this->log("Processing group prices");
        $this->groupPriceCsv->openIter($accountGroupPriceFile);
        $this->groupPriceCsv->take(1); //Discard first row

        //AccountGroupID|ProductID|Price
        while($toProcess = $this->groupPriceCsv->take(self::CHUNK_SIZE)) {
            $rulesForInsertion = [];
            foreach($toProcess as $row) {
                //Whitelist/blacklist logic
                $whitelist = $this->isWhitelisted($row[1]);
                $noPrice = $this->isEmptyOrWhitespace($row[2]);
                //Whitelist mode (Non-empty group price means visible) || Blacklist mode (Empty group price means not visible)
                if(($whitelist & !$noPrice) || (!$whitelist && $noPrice)) {
                    $rulesForInsertion[] = [
                        "product_id" => $row[1],
                        "group_id" => $row[0]
                    ];
                }
            }
            $this->getConnection()->insertOnDuplicate($this->tmpTable, $rulesForInsertion);
        }
        $this->groupPriceCsv->closeIter();
    }

    private function buildFinalRules(): void
    {
        $this->log("Building final ruleset");

        //Prepare the final rules ready for attributes
        $this->getConnection()->query(
        "INSERT INTO {$this->finalRulesTable} (product_id, rule)
            SELECT spm.entity_id, CONCAT(IF(sft.whitelist, '', '!'), GROUP_CONCAT(group_id ORDER BY group_id ASC)) FROM {$this->tmpTable} cct
                INNER JOIN {$this->productMappingTable} spm
                    ON cct.product_id = spm.sinch_product_id
                INNER JOIN {$this->flagTable} sft
                    ON cct.product_id = sft.product_id
                GROUP BY spm.entity_id"
        );

        $distinctRules = $this->getConnection()->fetchCol("SELECT DISTINCT rule FROM {$this->finalRulesTable}");
        $ruleCount = count($distinctRules);
        $this->log("{$ruleCount} distinct rules");

        foreach($distinctRules as $rule) {
            $products = $this->getConnection()->fetchCol(
                "SELECT product_id FROM {$this->finalRulesTable} WHERE rule = :value",
                [":value" => $rule]
            );

            if(!empty($products)) {
                $this->massProdValues->updateAttributes(
                    $products,
                    [self::ATTRIBUTE_NAME => $rule],
                    0 //store id (dummy value as they're global attributes)
                );
                $this->restrictCount += count($products);
            }
        }
        $this->log("Rule attribute values have been applied");

        //Clear the sinch_restrict value for products that no longer have a rule (but not non-sinch products)
        $noValueSinchProds = $this->getConnection()->fetchCol(
            "SELECT entity_id FROM {$this->cpeTable}
                WHERE entity_id NOT IN (SELECT product_id FROM {$this->finalRulesTable})
                AND sinch_product_id IN (SELECT product_id FROM {$this->flagTable})"
        );

        $numProds = count($noValueSinchProds);
        if($numProds > 0) {
            $this->log("Clearing attribute value for {$numProds} products");
            $this->massProdValues->updateAttributes(
                $noValueSinchProds,
                [self::ATTRIBUTE_NAME => ""],
                0
            );
        }
    }

    /**
     * @param string $value String to check for emptiness/whitespace
     * @return bool True if empty or whitespace
     */
    private function isEmptyOrWhitespace(string $value): bool
    {
        return empty($value) || empty(trim($value));
    }

    /**
     * @param int $product_id the Sinch product ID
     * @return bool
     */
    private function isWhitelisted(int $product_id): bool
    {
        return $this->getConnection()->fetchOne(
            "SELECT whitelist FROM {$this->flagTable} WHERE product_id = :product_id",
            [":product_id" => $product_id]
        );
    }
}