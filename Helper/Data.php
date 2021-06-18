<?php
namespace SITC\Sinchimport\Helper;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    private ResourceConnection $resourceConn;
    private Session\Proxy $customerSession;
    /** @var DirectoryList $dir */
    private $dir;
    private HttpContext $httpContext;

    private string $accountTable;
    private string $groupMappingTable;

    private Reader $moduleReader;

    public function __construct(
        Context $context,
        ResourceConnection $resourceConn,
        Session\Proxy $customerSession,
        DirectoryList\Proxy $dir,
        HttpContext $httpContext,
        Reader $moduleReader
    ) {
        parent::__construct($context);
        $this->resourceConn = $resourceConn;
        $this->customerSession = $customerSession;
        $this->dir = $dir;
        $this->httpContext = $httpContext;
        $this->accountTable = $this->resourceConn->getTableName('tigren_comaccount_account');
        $this->groupMappingTable = $this->resourceConn->getTableName('sinch_group_mapping');
        $this->moduleReader = $moduleReader;
    }

    public function getStoreConfig($configPath)
    {
        return $this->scopeConfig->getValue(
            $configPath,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isModuleEnabled($moduleName): bool
    {
        return $this->_moduleManager->isEnabled($moduleName);
    }

    public function isCategoryVisibilityEnabled(): bool
    {
        return $this->isModuleEnabled("Tigren_CompanyAccount") &&
            $this->getStoreConfig('sinchimport/category_visibility/enable') == 1;
    }

    public function isProductVisibilityEnabled(): bool
    {
        return $this->isModuleEnabled("Tigren_CompanyAccount") &&
            $this->getStoreConfig('sinchimport/product_visibility/enable') == 1;
    }

    /**
     * Return the current account group ID, or false if not logged in
     * @return int|bool
     */
    public function getCurrentAccountGroupId()
    {
        return $this->httpContext->getValue(\SITC\Sinchimport\Plugin\VaryContext::CONTEXT_ACCOUNT_GROUP);
    }

    public function getAccountGroupForAccount($accountId): ?int
    {
        return $this->resourceConn->getConnection()->fetchOne(
            "SELECT account_group_id FROM {$this->accountTable} WHERE account_id = :account_id",
            [":account_id" => $accountId]
        );
    }

    /**
     * Schedule an import for execution as soon as possible
     * @param string $importType The type of import, one of "PRICE STOCK" and "FULL"
     * @return void
     */
    public function scheduleImport(string $importType) {
        $importStatus = $this->resourceConn->getTableName('sinch_import_status');
        //Clear the status table so the admin panel doesn't immediately mark it as complete
        if($this->resourceConn->getConnection()->isTableExists($importStatus)) {
            $this->resourceConn->getConnection()->query(
                "DELETE FROM {$importStatus}"
            );
        }

        $importStatusStat = $this->resourceConn->getTableName('sinch_import_status_statistic');
        $this->resourceConn->getConnection()->query(
            "INSERT INTO {$importStatusStat} (
                start_import,
                finish_import,
                import_type,
                global_status_import,
                import_run_type,
                error_report_message
            )
            VALUES(
                NOW(),
                '0000-00-00 00:00:00',
                :import_type,
                'Scheduled',
                'MANUAL',
                ''
            )",
            [":import_type" => $importType]
        );
    }

    /**
     * Returns whether the index lock is currently held
     * (indicating a running import, or an intentional indexing pause)
     * @return bool Whether the lock is currently held
     */
    public function isIndexLockHeld(): bool
    {
        //Manual lock indexing flag (for testing/holding the indexers for other reasons)
        if (file_exists($this->dir->getPath("var") . "/sinch_lock_indexers.flag")) {
            return true;
        }

        //Import lock
        $current_vhost = $this->scopeConfig->getValue(
            'web/unsecure/base_url',
            ScopeInterface::SCOPE_STORE
        );
        $is_lock_free = $this->resourceConn->getConnection()->fetchOne("SELECT IS_FREE_LOCK('sinchimport_{$current_vhost}')");
        if ($is_lock_free === '0') {
            return true;
        }
        return false;
    }

    /**
     * Returns the customer group ID for the given account group ID.
     * Returns null if there is no corresponding group
     * @param int $accountGroupId
     * @return int|null
     */
    public function getCustomerGroupForAccountGroup(int $accountGroupId): ?int
    {
        $res = $this->resourceConn->getConnection()->fetchOne(
            "SELECT magento_id FROM {$this->groupMappingTable} WHERE sinch_id = :accountGroupId",
            [':accountGroupId' => $accountGroupId]
        );
        if(!is_numeric($res)){
            return null;
        }
        return (int)$res;
    }

    public function getCustomerGroupForAccount($accountId): ?int
    {
        $accountGroup = $this->getAccountGroupForAccount($accountId);
        if(empty($accountGroup)) {
            return null;
        }
        return $this->getCustomerGroupForAccountGroup($accountGroup);
    }

    /**
     * Return whether Multi-source inventory is enabled (both the MSI modules, and the setting within the import)
     * @return bool
     */
    public function isMSIEnabled(): bool
    {
        return $this->isModuleEnabled('Magento_Inventory') && $this->getStoreConfig('sinchimport/general/multisource_stock');
    }

    public function experimentalSearchEnabled(): bool
    {
        return $this->getStoreConfig('sinchimport/misc/experimental_search_features') == 1;
    }

    public function popularityBoostEnabled(): bool
    {
        return $this->getStoreConfig('sinchimport/search/popularity_boost') == 1;
    }

    public function popularityBoostFactor(): float
    {
        return (float)$this->getStoreConfig('sinchimport/search/popularity_boost_factor');
    }

    public function getProductAttributeId(string $attributeCode): ?int
    {
        return $this->getAttributeId(Product::ENTITY, $attributeCode);
    }

    public function getCategoryAttributeId(string $attributeCode): ?int
    {
        return $this->getAttributeId(Category::ENTITY, $attributeCode);
    }

    public function getAttributeId(string $type, string $code): ?int
    {
        $conn = $this->resourceConn->getConnection();

        $eav_entity_type = $this->resourceConn->getTableName('eav_entity_type');
        $eav_attribute = $this->resourceConn->getTableName('eav_attribute');

        $attributeId = $conn->fetchOne(
            "SELECT attribute_id FROM {$eav_attribute} ea
                INNER JOIN {$eav_entity_type} eet ON ea.entity_type_id = eet.entity_type_id
                WHERE eet.entity_type_code = :type AND ea.attribute_code = :code",
            [':type' => $type, ':code' => $code]
        );
        if ($attributeId != false) {
            return (int)$attributeId;
        }
        return null;
    }

    public function getModuleDirectory(string $type): string
    {
	    return $this->moduleReader->getModuleDir($type, 'SITC_Sinchimport');
    }

    /**
     * @return bool The value of the "Indexing Separately" config option
     */
    public function indexSeparately(): bool
    {
        return $this->getStoreConfig('sinchimport/sinch_import_fullstatus/indexing_separately') == 1;
    }

    /**
     * @return string|null The current import type ('FULL' or 'PRICE STOCK') or null if no import is running
     */
    public function currentImportType(): ?string
    {
        $sinch_import_status_statistic = $this->resourceConn->getTableName('sinch_import_status_statistic');
        return $this->resourceConn->getConnection()->fetchOne(
            "SELECT import_type FROM $sinch_import_status_statistic WHERE global_status_import = 'Run' AND id = (SELECT MAX(id) FROM $sinch_import_status_statistic) ORDER BY start_import DESC LIMIT 1"
        );
    }
}
