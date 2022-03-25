<?php
namespace SITC\Sinchimport\Model\Import;

/**
 * Class CustomerGroupPrice
 *
 * NOTE: ScopedProductTierPriceManagementInterface is avoided because its too slow
 *
 * @package SITC\Sinchimport\Model\Import
 */
class CustomerGroupPrice extends AbstractImportSection
{
    const LOG_PREFIX = "CustomerGroupPrice: ";
    const LOG_FILENAME = "customer_groups_price";

    const CUSTOMER_GROUPS = 'group_name';
    const PRICE_COLUMN = 'customer_group_price';
    const CHUNK_SIZE = 1000;
    const INSERT_THRESHOLD = 500; //Inserts are most efficient around batches of 500 (possibly related to TierPricePersistence inserting in batches of 500?)

    const GROUP_SUFFIX = " (SITC)";

    const PRICE_TABLE_CURRENT = "sinch_customer_group_price_cur";
    const PRICE_TABLE_NEXT = "sinch_customer_group_price_nxt";

    private $customerGroupCount = 0;
    private $customerGroupPriceCount = 0;

    /**
     * @var \SITC\Sinchimport\Helper\Data
     */
    private $helper;
    /**
     * CSV parser
     * @var \SITC\Sinchimport\Util\CsvIterator
     */
    private $csv;
    /**
     * @var \Magento\Customer\Api\Data\GroupInterfaceFactory
     */
    private $groupFactory;
    /**
     * @var \Magento\Customer\Api\GroupRepositoryInterface
     */
    private $groupRepository;
    /**
     * @var \Magento\Catalog\Api\TierPriceStorageInterface
     */
    private $tierPriceStorage;
    /**
     * @var \Magento\Catalog\Api\Data\TierPriceInterface
     */
    private $tierPriceFactory;
    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;


    /**
     * @var string customer_group table
     */
    private $customerGroupTable;
    /**
     * @var string sinch_products_mapping table
     */
    private $sinchProductsMappingTable;
    /**
     * @var string sinch_group_mapping table
     */
    private $mappingTable;
    /**
     * @var string catalog_product_entity_tier_price table
     */
    private $tierPriceTable;

    /** @var string */
    private $groupPriceTableCurrent;
    /** @var string */
    private $groupPriceTableNext;


    /**
     * @var array Holds a cache of sinchGroup -> magentoGroupId conversions
     *
     */
    private $groupIdCache = [];
    /**
     * @var array Holds a cache of sinchGroup -> magentoGroupCode conversions
     */
    private $groupCodeCache = [];


    /**
     * CustomerGroupPrice constructor.
     * @param \SITC\Sinchimport\Util\CsvIterator $csv
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Symfony\Component\Console\Output\ConsoleOutput $output
     */
    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConn,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \SITC\Sinchimport\Helper\Data $helper,
        \SITC\Sinchimport\Util\CsvIterator $csv,
        \Magento\Customer\Api\Data\GroupInterfaceFactory $groupFactory,
        \Magento\Customer\Api\GroupRepositoryInterface $groupRepository,
        \Magento\Catalog\Api\TierPriceStorageInterface $tierPriceStorage,
        \Magento\Catalog\Api\Data\TierPriceInterfaceFactory $tierPriceFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        parent::__construct($resourceConn, $output);
        $this->helper = $helper;
        $this->csv = $csv->setLineLength(256)->setDelimiter("|");
        $this->groupFactory = $groupFactory;
        $this->groupRepository = $groupRepository;
        $this->tierPriceStorage = $tierPriceStorage;
        $this->tierPriceFactory = $tierPriceFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        
        $this->customerGroupTable = $this->getTableName('customer_group');
        $this->sinchProductsMappingTable = $this->getTableName('sinch_products_mapping');
        $this->mappingTable = $this->getTableName('sinch_group_mapping');
        $this->tierPriceTable = $this->getTableName('catalog_product_entity_tier_price');

        $this->groupPriceTableCurrent = $this->getTableName(self::PRICE_TABLE_CURRENT);
        $this->groupPriceTableNext = $this->getTableName(self::PRICE_TABLE_NEXT);
    }

    private function createMappingTable()
    {
        $groupTable = $this->getTableName('customer_group');
        $this->getConnection()->query("CREATE TABLE IF NOT EXISTS {$this->mappingTable} (
            sinch_id int(10) unsigned NOT NULL UNIQUE KEY PRIMARY KEY,
            magento_id int(10) unsigned NOT NULL UNIQUE KEY,
            FOREIGN KEY (magento_id) REFERENCES {$groupTable} (customer_group_id) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci");
    }

    private function initDeltaPricing()
    {
        $conn = $this->getConnection();
        if (!$conn->isTableExists($this->groupPriceTableCurrent) && !$conn->isTableExists($this->groupPriceTableNext)) {
            $this->log("Detected first import of delta pricing, clearing tier prices");
            $this->getConnection()->query("DELETE FROM {$this->tierPriceTable}");
            $this->getConnection()->query("ALTER TABLE {$this->tierPriceTable} AUTO_INCREMENT=1");
        }

        $this->getConnection()->query("CREATE TABLE IF NOT EXISTS {$this->groupPriceTableCurrent} (
            sinch_group_id int(10) unsigned NOT NULL,
            sinch_product_id int(10) unsigned NOT NULL,
            price_type int(10) unsigned NOT NULL DEFAULT 1,
            price decimal(12,4) NOT NULL,
            magento_value_id int(11) DEFAULT NULL UNIQUE KEY,
            PRIMARY KEY (sinch_group_id, sinch_product_id, price_type),
            FOREIGN KEY (magento_value_id) REFERENCES {$this->tierPriceTable} (value_id) ON UPDATE CASCADE ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci");

        //Drop the table and make sure that the price column has a default negative value
        // Should ensure that we don't end up reading NULL as 0
        $this->getConnection()->query("DROP TABLE IF EXISTS {$this->groupPriceTableNext}");
        $this->getConnection()->query("CREATE TABLE IF NOT EXISTS {$this->groupPriceTableNext} (
            sinch_group_id int(10) unsigned NOT NULL,
            sinch_product_id int(10) unsigned NOT NULL,
            price_type int(10) unsigned NOT NULL DEFAULT 1,
            price decimal(12,4) NOT NULL DEFAULT -1,
            PRIMARY KEY (sinch_group_id, sinch_product_id, price_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci");
    }


    /**
     * @param string $customerGroupFile
     * @param string $customerGroupPriceFile
     * @throws \Exception
     */
    public function parse($customerGroupFile, $customerGroupPriceFile)
    {
        $this->log("Starting CustomerGroupPrice parse");
        $this->createMappingTable();
        $this->initDeltaPricing();

        $this->startTimingStep('Group parsing');
        $customerGroupCsv = $this->csv->getData($customerGroupFile);
        unset($customerGroupCsv[0]);

        foreach ($customerGroupCsv as $groupData) {
            //Sinch Group ID, Group Name
            $this->createOrUpdateGroup($groupData[0], $groupData[1]);
        }
        $this->endTimingStep();

        $this->log("Processed {$this->customerGroupCount} customer groups");
        $parseStart = $this->microtime_float();
        
        $this->startTimingStep('Group prices - LOAD DATA');
        $this->log("Loading new values into database for processing");
        $this->getConnection()->query(
            "LOAD DATA LOCAL INFILE '{$customerGroupPriceFile}'
                INTO TABLE {$this->groupPriceTableNext}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (sinch_group_id, sinch_product_id, price_type, price)"
        );
        $permitZero = $this->helper->getStoreConfig('sinchimport/general/permit_zero_price');
        $this->getConnection()->query(
            "DELETE FROM {$this->groupPriceTableNext} WHERE price < 0 OR price_type != 1 OR (price = 0 AND :permitZero = 0)",
            [':permitZero' => (int)$permitZero]
        );
        $this->endTimingStep();
        
        $this->log("New rules loaded, calculating delta");
        $this->customerGroupPriceCount = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM {$this->groupPriceTableNext}");
        $deletedCount = 0;
        $updatedCount = 0;
        $createdCount = 0;

        //Calculate delta rules
        //Deleted rules
        $this->startTimingStep('Group prices - Deletions');
        $this->getConnection()->beginTransaction();
        try {
            $toDelete = $this->getConnection()->fetchAll(
                "SELECT current.sinch_group_id, current.sinch_product_id, current.price_type, current.magento_value_id FROM {$this->groupPriceTableCurrent} current
                    LEFT JOIN {$this->groupPriceTableNext} next
                        ON current.sinch_group_id = next.sinch_group_id
                        AND current.sinch_product_id = next.sinch_product_id
                        AND current.price_type = next.price_type
                    WHERE next.price IS NULL"
            );
            $deletedCount = count($toDelete);
            $this->log("{$deletedCount} rules to be deleted");
            foreach ($toDelete as $rule) {
                if (!empty($rule['magento_value_id'])) {
                    $this->getConnection()->query(
                        "DELETE FROM {$this->tierPriceTable} WHERE value_id = :value_id",
                        [':value_id' => $rule['magento_value_id']]
                    );
                }
                $this->getConnection()->query(
                    "DELETE FROM {$this->groupPriceTableCurrent}
                        WHERE sinch_group_id = :sinch_group_id
                        AND sinch_product_id = :sinch_product_id
                        AND price_type = :price_type",
                    [
                        ":sinch_group_id" => $rule['sinch_group_id'],
                        ":sinch_product_id" => $rule['sinch_product_id'],
                        ":price_type" => $rule['price_type']
                    ]
                );
            }
            $toDelete = null;
            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();
            throw $e;
        } finally {
            $this->endTimingStep();
        }

        //Check auto-increment on tierPriceTable and shift its values if it gains us at least 1000 back
        $this->startTimingStep('Group prices - Reclaim auto_increment values');
        //MySQL refuses to set auto_increment to a value <= MAX(autoincrement_column) and auto adjusts it to MAX(autoincrement_column) + 1
        $this->getConnection()->query("ALTER TABLE {$this->tierPriceTable} AUTO_INCREMENT=1");
        $autoIncMax = $this->getConnection()->fetchOne("SELECT MAX(value_id) FROM {$this->tierPriceTable}");
        $autoIncMin = $this->getConnection()->fetchOne("SELECT MIN(value_id) FROM {$this->tierPriceTable}");
        $autoIncRange = $autoIncMax - $autoIncMin;
        $numEntries = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM {$this->tierPriceTable}");

        //There are some entries, and we gain at least 1000 auto_increment values from this operation
        if ($numEntries > 0 && $autoIncMin > 1000 && $autoIncMin > $autoIncRange) {
            $change = $autoIncMin - 1;
            $this->log("Altering tier price table to reclaim {$change} auto_increment values");
            $this->getConnection()->query(
                "UPDATE {$this->tierPriceTable} SET value_id = value_id - :change ORDER BY value_id ASC",
                [":change" => $change]
            );
            $this->getConnection()->query("ALTER TABLE {$this->tierPriceTable} AUTO_INCREMENT=1");
        }
        $this->endTimingStep();

        

        //Updated rules
        $this->startTimingStep('Group prices - Updates');
        //Map unmapped (so the update section hits as many rules as possible)
        $this->mapUnmappedRules();
        $this->getConnection()->beginTransaction();
        try {
            $toUpdate = $this->getConnection()->fetchAll(
                "SELECT current.magento_value_id, next.price FROM {$this->groupPriceTableNext} next
                INNER JOIN {$this->groupPriceTableCurrent} current
                    ON next.sinch_group_id = current.sinch_group_id
                    AND next.sinch_product_id = current.sinch_product_id
                    AND next.price_type = current.price_type
                WHERE next.price != current.price AND current.magento_value_id IS NOT NULL"
            );
            $updatedCount = count($toUpdate);
            $this->log("{$updatedCount} rules to be updated");
            foreach ($toUpdate as $updatedRule) {
                $this->getConnection()->query(
                    "UPDATE {$this->tierPriceTable} tp SET value = :price WHERE value_id = :value_id",
                    [
                        ":value_id" => $updatedRule['magento_value_id'],
                        ":price" => $updatedRule['price']
                    ]
                );
            }
            $toUpdate = null;
            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();
            throw $e;
        } finally {
            $this->endTimingStep();
        }
        

        //Otherwise missing rules
        $this->startTimingStep('Group prices - Creations');
        $this->getConnection()->beginTransaction();
        try {
            //Pull all updated data into current from next (this includes changed prices which were updated by the previous step)
            $this->getConnection()->query(
                "INSERT INTO {$this->groupPriceTableCurrent} (sinch_group_id, sinch_product_id, price_type, price)
                    SELECT sinch_group_id, sinch_product_id, price_type, price FROM {$this->groupPriceTableNext} next
                ON DUPLICATE KEY UPDATE price = next.price"
            );

            $remainingRules = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM {$this->groupPriceTableCurrent} WHERE magento_value_id IS NULL");
            $this->log("{$remainingRules} rules remaining to be added");

            //Insert missing rules into tier pricing, then immediately attempt to map them in PRICE_TABLE_CURRENT
            $this->insertMissingRules();
            $this->mapUnmappedRules();

            $nowRemaining = $this->getConnection()->fetchOne("SELECT COUNT(*) FROM {$this->groupPriceTableCurrent} WHERE magento_value_id IS NULL");
            $createdCount = $remainingRules - $nowRemaining;
            if ($createdCount > 0) {
                $this->log("Inserted and mapped {$createdCount} rules");
            }
            if ($nowRemaining > 0) {
                $this->log("The remaining {$nowRemaining} rules could not be created as their product or group doesn't exist in Magento");
            }

            $this->getConnection()->query("DELETE FROM {$this->groupPriceTableNext}");
            $this->getConnection()->commit();
        } catch (\Exception $e) {
            $this->getConnection()->rollBack();
            throw $e;
        } finally {
            $this->endTimingStep();
        }

        $rulesChanged = $deletedCount + $updatedCount + $createdCount;
        $this->log("Processed {$this->customerGroupPriceCount} group prices (changed {$rulesChanged})");
        $this->timingPrint();
    }

    /**
     * Creates the specified group if it doesn't exist, then adds a mapping entry linking the sinch ID to the Magento ID
     * @param int $sinchGroupId The Sinch Group ID
     * @param string $groupName The Sinch Group Name
     * @return void
     */
    private function createOrUpdateGroup($sinchGroupId, $groupName)
    {
        $fullGroupName = $groupName . self::GROUP_SUFFIX;
        $this->customerGroupCount += 1;
        $magentoGroupId = $this->getConnection()->fetchOne(
            "SELECT magento_id FROM {$this->mappingTable} WHERE sinch_id = :sinch_id",
            [":sinch_id" => $sinchGroupId]
        );
        if (!empty($magentoGroupId)) {
            $group = $this->groupRepository->getById($magentoGroupId);
            if ($group->getCode() != $fullGroupName && $groupName != "NOT LOGGED IN") {
                $group->setCode($fullGroupName);
                $this->groupRepository->save($group);
            }
            return;
        }
        
        //Special case, map a group named "NOT LOGGED IN" to the default Magento group with the same name
        if ($groupName == "NOT LOGGED IN") {
            $this->insertMapping($sinchGroupId, 0); //0 is the Magento ID for "NOT LOGGED IN"
            return;
        }

        //Group doesn't exist, create it
        $group = $this->groupFactory->create();
        $group->setCode($fullGroupName)
            ->setTaxClassId(3); //"Retail Customer" magic number (set to 3 on all default customer groups)
        try {
            $group = $this->groupRepository->save($group);
            $this->insertMapping($sinchGroupId, $group->getId());
        } catch (\Magento\Framework\Exception\State\InvalidTransitionException $e) {
            $this->log("Group unexpectedly exists, trying to remap it: {$groupName} ({$sinchGroupId})");
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('code', $fullGroupName, 'eq')
                ->create();
            $matchingGroups = $this->groupRepository->getList($criteria)->getItems();
            if (count($matchingGroups) > 0) {
                $this->insertMapping($sinchGroupId, $matchingGroups[0]->getId());
                return;
            }
            $this->log("FATAL: Unable to determine why we got InvalidTransitionException, rethrowing");
            throw $e;
        }
    }

    private function insertMapping($sinchId, $magentoId)
    {
        $this->getConnection()->query(
            "INSERT INTO {$this->mappingTable} (sinch_id, magento_id) VALUES(:sinch_id, :magento_id) ON DUPLICATE KEY UPDATE magento_id = VALUES(magento_id)",
            [
                ":sinch_id" => $sinchId,
                ":magento_id" => $magentoId
            ]
        );
    }

    /**
     * Maps tier price entries to equivalent PRICE_TABLE_CURRENT entries that have no mapping, where possible
     * Functionally idempotent, makes no changes where mappings exist or where no mapping is possible (e.g. due to the group or product not existing in the store)
     * @return void
     */
    private function mapUnmappedRules()
    {
        $this->getConnection()->query(
            "UPDATE {$this->groupPriceTableCurrent} current 
                INNER JOIN {$this->mappingTable} groupmap ON current.sinch_group_id = groupmap.sinch_id
                INNER JOIN {$this->sinchProductsMappingTable} productmap ON current.sinch_product_id = productmap.sinch_product_id
                INNER JOIN {$this->tierPriceTable} tp ON tp.all_groups = 0 AND tp.qty = 1.0 AND tp.website_id = 0 AND tp.customer_group_id = groupmap.magento_id AND tp.entity_id = productmap.entity_id
            SET magento_value_id = tp.value_id
            WHERE magento_value_id IS NULL"
        );
    }

    /**
     * Takes rules from PRICE_TABLE_CURRENT which have no mapping, and creates a functionally identical tier price entry where possible
     * While this function is idempotent, its cost is not, so it is recommended to call mapUnmappedRules both before and after
     * @return void
     */
    private function insertMissingRules()
    {
        $this->getConnection()->query(
            "INSERT INTO {$this->tierPriceTable}
                (entity_id, all_groups, customer_group_id, qty, value, website_id)
                SELECT productmap.entity_id, 0, groupmap.magento_id, 1.0, current.price, 0
                FROM {$this->groupPriceTableCurrent} current
                    INNER JOIN {$this->mappingTable} groupmap ON current.sinch_group_id = groupmap.sinch_id
                    INNER JOIN {$this->sinchProductsMappingTable} productmap ON current.sinch_product_id = productmap.sinch_product_id
                WHERE magento_value_id IS NULL
                ON DUPLICATE KEY UPDATE value = current.price"
        );
    }
}
