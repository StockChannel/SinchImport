<?php
namespace SITC\Sinchimport\Model\Import;

/**
 * Class CustomerGroupPrice
 * 
 * NOTE: ScopedProductTierPriceManagementInterface is avoided because its too slow
 * 
 * @package SITC\Sinchimport\Model\Import
 */
class CustomerGroupPrice extends AbstractImportSection {
    const LOG_PREFIX = "CustomerGroupPrice: ";
    const LOG_FILENAME = "customer_groups_price";

    const CUSTOMER_GROUPS = 'group_name';
    const PRICE_COLUMN = 'customer_group_price';
    const CHUNK_SIZE = 1000;
    const INSERT_THRESHOLD = 500; //Inserts are most efficient around batches of 500 (possibly related to TierPricePersistence inserting in batches of 500?)

    const GROUP_SUFFIX = " (SITC)";

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
    ){
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

        $this->enableUnsafeOptimizations = $helper->getStoreConfig('sinchimport/group_pricing/unsafe_optimizations');
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


    /**
     * @param string $customerGroupFile
     * @param string $customerGroupPriceFile
     * @throws \Exception
     */
    public function parse($customerGroupFile, $customerGroupPriceFile)
    {
        $this->log("Starting CustomerGroupPrice parse");
        $this->createMappingTable();
        $parseStart = $this->microtime_float();

        $customerGroupCsv = $this->csv->getData($customerGroupFile);
        unset($customerGroupCsv[0]);

        $this->csv->openIter($customerGroupPriceFile);
        $this->csv->take(1); //Discard first row

        foreach($customerGroupCsv as $groupData){
            //Sinch Group ID, Group Name
            $this->createOrUpdateGroup($groupData[0], $groupData[1]);
        }

        $elapsed = number_format($this->microtime_float() - $parseStart, 2);
        $this->log("Processed {$this->customerGroupCount} customer groups in {$elapsed} seconds");
        $parseStart = $this->microtime_float();

        
        //Begin transaction (to speed up the inserts?)
        if($this->enableUnsafeOptimizations) {
            $this->getConnection()->beginTransaction();
        }
        try {
            $this->log("Clearing old tier prices");
            $this->clearSinchTierPrices();
            $customerGroupPriceData = [];
            $this->log("Begin processing tier prices");
            while($toProcess = $this->csv->take(self::CHUNK_SIZE)) {
                //Process price records
                foreach($toProcess as $priceData){
                    if(!is_numeric($priceData[3]) || ((float)$priceData[3]) <= 0.0) {
                        //Ignore invalid rules
                        continue;
                    }
                    if($priceData[2] != 1) {
                        $this->log("Unknown price type ID: " . $priceData[2]);
                        continue;
                    }

                    $data = $this->prepareTierPrice($priceData[0], $priceData[1], $priceData[3]);
                    if(empty($data)){
                        continue;
                    }
                    $customerGroupPriceData[] = $data;
                    $this->customerGroupPriceCount += 1;
                }
                if(count($customerGroupPriceData) >= self::INSERT_THRESHOLD){
                    $this->updateTierPrices($customerGroupPriceData);
                    $customerGroupPriceData = [];
                }
            }
            if(count($customerGroupPriceData) > 0){
                $this->updateTierPrices($customerGroupPriceData);
            }
            $this->csv->closeIter();

            //No error, commit
            if($this->enableUnsafeOptimizations) {
                $this->getConnection()->commit();
            }
        } catch (\Exception $e) {
            //Got an error, rollback
            if($this->enableUnsafeOptimizations) {
                $this->getConnection()->rollBack();
            }
            throw $e;
        }
        

        //Cleanup the old format data, if present
        $tmpTable = $this->getTableName('sinch_customer_group_price_tmp');
        $this->getConnection()->query("DROP TABLE IF EXISTS {$tmpTable}");
        $cgpTable = $this->getTableName('sinch_customer_group_price');
        $this->getConnection()->query("DELETE FROM {$cgpTable}");

        $elapsed = $this->microtime_float() - $parseStart;
        $this->log("Processed {$this->customerGroupPriceCount} group prices in {$elapsed} seconds");
    }

    /**
     * Clears all Sinch customer groups
     * @return void
     */
    private function clearSinchGroups()
    {
        $magentoGroupIds = $this->getConnection()->fetchCol("SELECT magento_id FROM {$this->mappingTable}");
        foreach($magentoGroupIds as $groupId){
            $this->groupRepository->deleteById($groupId);
        }
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
        if(!empty($magentoGroupId)){
            $group = $this->groupRepository->getById($magentoGroupId);
            if($group->getCode() != $fullGroupName) {
                $group->setCode($fullGroupName);
                $this->groupRepository->save($group);
            }
            return;
        }

        //Group doesn't exist, create it
        $group = $this->groupFactory->create();
        $group->setCode($fullGroupName)
            ->setTaxClassId(3); //"Retail Customer" magic number (set to 3 on all default customer groups)
        try {
            $group = $this->groupRepository->save($group);
            $this->insertMapping($sinchGroupId, $group->getId());
        } catch(\Magento\Framework\Exception\State\InvalidTransitionException $e){
            $this->log("Group unexpectedly exists, trying to remap it: {$groupName} ({$sinchGroupId})");
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('code', $fullGroupName, 'eq')
                ->create();
            $matchingGroups = $this->groupRepository->getList($criteria)->getItems();
            if(count($matchingGroups) > 0) {
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

    private function clearSinchTierPrices()
    {
        $this->getConnection()->query(
            "DELETE FROM {$this->tierPriceTable} WHERE all_groups = 0 AND qty = 1 AND entity_id IN (SELECT entity_id FROM {$this->sinchProductsMappingTable})"
        );
    }

    /**
     * Returns the SKU for a given Sinch Product ID, or null (if the product could not be matched)
     * @param int $sinchProdId
     * @return string|null
     */
    private function getSkuBySinchProduct($sinchProdId)
    {
        return $this->getConnection()->fetchOne(
            "SELECT sku FROM {$this->sinchProductsMappingTable} WHERE sinch_product_id = :sinch_product_id",
            [":sinch_product_id" => $sinchProdId]
        );
    }

    /**
     * Returns the Magento group code for a given Sinch Group ID, or null (if the group could not be matched)
     * @param int $sinchGroupId
     * @return string|null
     */
    private function getGroupCodeBySinchGroup($sinchGroupId)
    {
        return $this->getConnection()->fetchOne(
            "SELECT customer_group_code FROM {$this->customerGroupTable} WHERE customer_group_id IN (SELECT magento_id FROM {$this->mappingTable} WHERE sinch_id = :sinch_id)",
            [":sinch_id" => $sinchGroupId]
        );
    }

    /**
     * Prepare a tier price for batched insertion. Returns null on match failure
     * @param int $grpId Sinch Group ID
     * @param int $prodId Sinch Product ID
     * @param float $price Price
     * @return mixed|null
     */
    private function prepareTierPrice($grpId, $prodId, $price)
    {
        if($this->enableUnsafeOptimizations){
            $conn = $this->getConnection();
            $magProdId = $conn->fetchOne(
                "SELECT entity_id FROM {$this->sinchProductsMappingTable} WHERE sinch_product_id = :sinch_product_id",
                [":sinch_product_id" => $prodId]
            );
            if(empty($magProdId)){
                $this->log("Warning: No entity ID found for Sinch product ID " . $prodId, false);
                return null;
            }
            $magGrpId = $this->helper->getCustomerGroupForAccountGroup($grpId);
            if(empty($magGrpId)){
                $this->log("Warning: No Magento group ID found for Account group: " . $grpId);
                return null;
            }
            return [
                "all_groups" => 0,
                "qty" => 1.0,
                "website_id" => 0,
                "customer_group_id" => $magGrpId,
                "entity_id" => $magProdId,
                "value" => $price
            ];
        }

        $sku = $this->getSkuBySinchProduct($prodId);
        if(empty($sku)) {
            $this->log("Warning: No SKU found for Sinch product ID " . $prodId, false);
            return null;
        }
        $groupCode = $this->getGroupCodeBySinchGroup($grpId);
        if(empty($groupCode)){
            $this->log("Warning: No Magento group code found for Account group: " . $grpId);
            return null;
        }

        return $this->tierPriceFactory->create()
            ->setPriceType(\Magento\Catalog\Api\Data\TierPriceInterface::PRICE_TYPE_FIXED)
            ->setQuantity(1.0)
            ->setWebsiteId(0) //Admin website ID (all sites)
            ->setSku($sku)
            ->setCustomerGroup($groupCode)
            ->setPrice((float)$priceData[3]);
    }

    private function updateTierPrices($prices)
    {
        if($this->enableUnsafeOptimizations){
            $this->getConnection()->insertOnDuplicate(
                $this->tierPriceTable,
                $prices,
                ['value']
            );
            return;
        }

        $result = $this->tierPriceStorage->update($prices);
        foreach($result as $updateResult){
            $message = $updateResult->getMessage();
            foreach($updateResult->getParameters() as $k => $v) {
                $message = str_replace('%'.$k, $v, $message);
            }
            $this->log($message);
        }
    }
}