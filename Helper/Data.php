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
use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManager;
use SITC\Sinchimport\Plugin\VaryContext;

class Data extends AbstractHelper
{
    private ResourceConnection $resourceConn;
    private Session\Proxy $customerSession;
    private DirectoryList $dir;
    private HttpContext $httpContext;
    private StoreManager $storeManager;
    private TransportBuilder $transportBuilder;

    private string $accountTable;
    private string $groupMappingTable;
    private ?int $defaultStoreId = null;

    private Reader $moduleReader;

    public function __construct(
        Context $context,
        ResourceConnection $resourceConn,
        Session\Proxy $customerSession,
        DirectoryList\Proxy $dir,
        HttpContext $httpContext,
        StoreManager $storeManager,
        TransportBuilder $transportBuilder,
        Reader $moduleReader
    ) {
        parent::__construct($context);
        $this->resourceConn = $resourceConn;
        $this->customerSession = $customerSession;
        $this->dir = $dir;
        $this->httpContext = $httpContext;
        $this->storeManager = $storeManager;
        $this->transportBuilder = $transportBuilder;
        $this->accountTable = $this->resourceConn->getTableName('tigren_comaccount_account');
        $this->groupMappingTable = $this->resourceConn->getTableName('sinch_group_mapping');
        $this->moduleReader = $moduleReader;
    }

    public function getStoreConfig($configPath, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $configPath,
            ScopeInterface::SCOPE_STORE,
            $storeId
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

    public function clearStockReservations(): bool
    {
        return $this->getStoreConfig('sinchimport/stock/clear_reservations') == 1;
    }

    /**
     * Return the current account group ID, or false if not logged in
     * @return int|bool
     */
    public function getCurrentAccountGroupId()
    {
        if ($this->httpContext->getValue(VaryContext::CONTEXT_DEPERSONALIZED)) {
            //Depersonalize has run, and the httpContext has the value
            return $this->httpContext->getValue(VaryContext::CONTEXT_ACCOUNT_GROUP);
        }
        //Pre-depersonalize, retrieve manually
        if ($this->isModuleEnabled('Tigren_CompanyAccount') && $this->customerSession->isLoggedIn()) {
            $attr = $this->customerSession->getCustomerData()->getCustomAttribute('account_id');
            if(!empty($attr)){
                return $this->getAccountGroupForAccount($attr->getValue());
            }
        }
        return false;
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
    public function scheduleImport(string $importType): void
    {
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

    public function enhancedSearchEnabled(): bool
    {
        return $this->getStoreConfig('sinchimport/search/enable_enhanced') == 1;
    }

    private const VALID_SCORING_MODES = ['multiply', 'sum', 'avg', 'first', 'max', 'min'];
    private const VALID_BOOST_MODES = ['multiply', 'replace', 'sum', 'avg', 'max', 'min'];
    private const VALID_MODIFIERS = ['none', 'log', 'log1p', 'log2p', 'ln', 'ln1p', 'ln2p', 'square', 'sqrt', 'reciprocal'];

    public function popularityBoostEnabled(): bool
    {
        return $this->getStoreConfig('sinchimport/popularity_boost/enable') == 1;
    }

    public function popularityScoringMode(): string
    {
        $value = $this->getStoreConfig('sinchimport/popularity_boost/scoring_mode');
        if (!in_array($value, self::VALID_SCORING_MODES)) return 'max';
        return $value;
    }

    public function popularityBoostMode(): string
    {
        $value = $this->getStoreConfig('sinchimport/popularity_boost/boost_mode');
        if (!in_array($value, self::VALID_BOOST_MODES)) return 'sum';
        return $value;
    }

    public function scoreBoostFactor(): float
    {
        return (float)$this->getStoreConfig('sinchimport/popularity_boost/score_factor');
    }

    public function scoreBoostModifier(): string
    {
        $value = $this->getStoreConfig('sinchimport/popularity_boost/score_modifier');
        if (!in_array($value, self::VALID_MODIFIERS)) return 'log1p';
        return $value;
    }

    public function scoreBoostWeight(): int
    {
        return (int)$this->getStoreConfig('sinchimport/popularity_boost/score_weight');
    }

    public function monthlyPopularityBoostFactor(): float
    {
        return (float)$this->getStoreConfig('sinchimport/popularity_boost/monthly_sales_factor');
    }

    public function monthlyPopularityBoostModifier(): string
    {
        $value = $this->getStoreConfig('sinchimport/popularity_boost/monthly_sales_modifier');
        if (!in_array($value, self::VALID_MODIFIERS)) return 'log1p';
        return $value;
    }

    public function monthlyPopularityBoostWeight(): int
    {
        return (int)$this->getStoreConfig('sinchimport/popularity_boost/monthly_sales_weight');
    }

    public function yearlyPopularityBoostFactor(): float
    {
        return (float)$this->getStoreConfig('sinchimport/popularity_boost/yearly_sales_factor');
    }

    public function yearlyPopularityBoostModifier(): string
    {
        $value = $this->getStoreConfig('sinchimport/popularity_boost/yearly_sales_modifier');
        if (!in_array($value, self::VALID_MODIFIERS)) return 'log1p';
        return $value;
    }

    public function yearlyPopularityBoostWeight(): int
    {
        return (int)$this->getStoreConfig('sinchimport/popularity_boost/yearly_sales_weight');
    }

    public function searchesBoostFactor(): float
    {
        return (float)$this->getStoreConfig('sinchimport/popularity_boost/searches_factor');
    }

    public function searchesBoostModifier(): string
    {
        $value = $this->getStoreConfig('sinchimport/popularity_boost/searches_modifier');
        if (!in_array($value, self::VALID_MODIFIERS)) return 'log1p';
        return $value;
    }

    public function searchesBoostWeight(): int
    {
        return (int)$this->getStoreConfig('sinchimport/popularity_boost/searches_weight');
    }

    public function badgesEnabled(): bool
    {
        return $this->getStoreConfig('sinchimport/badges/enable') == 1;
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

    public function isInStockFilterEnabled()
    {
        return $this->getStoreConfig('sinchimport/stock/in_stock_filter_enable');
    }

    public function sendSuccessEmail(): bool
    {
        echo "Send success email\n";
        $destEmail = $this->getStoreConfig('sinchimport/general/success_email_dest');
        // If email not set or not valid, just return true without doing anything
        if (empty($destEmail) || filter_var($destEmail, FILTER_VALIDATE_EMAIL) === false) return true;
        echo "Email non-empty and valid\n";
        try {
            $sinch_import_status_statistic = $this->resourceConn->getTableName('sinch_import_status_statistic');
            $importData = $this->resourceConn->getConnection()->fetchRow(
                "SELECT start_import, import_type FROM $sinch_import_status_statistic ORDER BY start_import DESC LIMIT 1"
            );
            $this->transportBuilder->setTemplateIdentifier('sinchimport_import_completed')
                ->setTemplateOptions(
                    [
                        'area' => Area::AREA_ADMINHTML,
                        'store' => $this->storeManager->getStore()->getId(),
                    ]
                )
                ->setTemplateVars($importData)
                ->setFromByScope($this->getStoreConfig('trans_email/ident_general')) //Use the General Contact identity as sender
                ->addTo($destEmail, "Sinchimport Administrator")
                ->getTransport()
                ->sendMessage();
        } catch (Exception $e) {
            echo $e->getTraceAsString();
            return false;
        }
        return true;
    }

    /**
     * Check the visibility of $product, assuming the current customer is in $accountGroup, returning whether
     * the product and it's children are to be considered visible
     * @param Product $product
     * @param ?int $accountGroup
     * @return bool
     */
    public function checkProductVisibility(Product $product, ?int $accountGroup): bool
    {
        $sinch_restrict = $product->getSinchRestrict();
        $childrenVisible = true;
        // Special logic for types with children (make sure child rules permit visibility)
        $childCCRules = $this->getChildCCRules($product);
        if (!empty($childCCRules)) {
            $childrenVisible = array_reduce(
                $childCCRules,
                function($carry, $rule) use ($accountGroup) {
                    return $carry && self::evalCCRule($accountGroup, $rule);
                },
                true
            );
        }
        return self::evalCCRule($accountGroup, $sinch_restrict) && $childrenVisible;
    }

    /**
     * Return an array containing all Custom Catalog rules for the children of $product
     * @param Product $product Product to return child restriction rules for
     * @return array CC Rules
     */
    public function getChildCCRules(Product $product): array
    {
        $children = $product->getTypeInstance()->getChildrenIds($product->getId());
        if (!empty($children)) {
            $conn = $this->resourceConn->getConnection();
            $catalog_product_entity_text = $conn->getTableName('catalog_product_entity_text');
            $eav_attribute = $conn->getTableName('eav_attribute');

            // Return of getChildrenIds is a bit fucking weird, so normalize the array layout, so we can use it for binds
            $children = array_map(function ($value) { return array_key_first($value); }, array_values($children));

            $childSub = implode(", ", array_fill(0, count($children), '?'));
            return $conn->fetchCol(
                "SELECT value FROM $catalog_product_entity_text cpev
                        WHERE attribute_id = (SELECT attribute_id FROM $eav_attribute WHERE attribute_code = 'sinch_restrict')
                        AND entity_id IN ($childSub)",
                $children
            );
        }
        return [];
    }

    /**
     * Evaluate a Custom Catalog rule in the context of $currentGroup, returning
     * whether the rule permits visibility to that group
     * @param ?int $currentGroup Group to evaluate the rule for
     * @param string|null $rule Rule to evaluate
     * @return bool
     */
    public static function evalCCRule(?int $currentGroup, ?string $rule): bool
    {

        if (empty($rule)) return true;
        $blacklist = str_starts_with($rule, "!");
        if (empty($currentGroup)) {
            return $blacklist;
        }
        if($blacklist) {
            $rule = substr($rule, 1);
        }
        $product_account_groups = explode(",", $rule);

        if((!$blacklist && in_array($currentGroup, $product_account_groups)) || //Whitelist and account group in list
            ($blacklist && !in_array($currentGroup, $product_account_groups))) { //Blacklist and account group not in list
            return true;
        }
        return false;
    }


    /**
     * Merge multiple Custom Catalog rules together, returning a single rule
     * which consists of the most restrictive subset of the given rules
     * @param string[] $current Current rules
     * @param string[] $additional Additional rules to merge into current
     * @return string[]
     */
    public static function mergeCCRules(array $current, array $additional): array
    {
        // Ensure that some values were given (particularly $current,
        // where in Datacom NZ Prod we observed it cause a crash)
        if (empty($current)) {
            $current = [""];
        }
        if (empty($additional)) {
            $additional = [""];
        }
        // Ensure we only get single value arrays
        if (count($current) > 1) {
            $merged = [""];
            foreach ($current as $entry) {
                $merged = self::mergeCCRules($merged, [$entry]);
            }
            $current = $merged;
        }
        $main = $current[0];
        // Ditto for additional
        if (count($additional) > 1) {
            $merged = [""];
            foreach ($additional as $entry) {
                $merged = self::mergeCCRules($merged, [$entry]);
            }
            $additional = $merged;
        }
        $second = $additional[0];
        // An empty rule is considered to be an empty blacklist
        $mainBlacklist = str_starts_with($main, "!") || strlen($main) === 0;
        $secondBlacklist = str_starts_with($second, "!") || strlen($second) === 0;
        if ($mainBlacklist) {
            $main = substr($main, 1);
        }
        if ($secondBlacklist) {
            $second = substr($second, 1);
        }
        // Check length of string to avoid including empty string in groups list
        $mainGroups = strlen($main) === 0 ? [] : explode(",", $main);
        $secondGroups = strlen($second) === 0 ? [] : explode(",", $second);

        $finalGroups = [];
        if ($mainBlacklist && $secondBlacklist) {
            // Merge blacklist rules
            return ["!" . implode(",", array_unique(array_merge($mainGroups, $secondGroups)))];
        } else if (!$mainBlacklist && !$secondBlacklist) {
            // Merge whitelist rules
            $finalGroups = array_intersect($mainGroups, $secondGroups);
        } else if (!$mainBlacklist && $secondBlacklist) {
            // One is whitelist, remove two's values from it
            $finalGroups = array_diff($mainGroups, $secondGroups);
        } else /*if ($mainBlacklist && !$secondBlacklist)*/ {
            // Two is whitelist, remove one's values from it
            $finalGroups = array_diff($secondGroups, $mainGroups);
        }
        // The above 3 conditions need special behaviour to prevent rule being set to empty string when no groups can see it.
        if (empty($finalGroups)) {
            $finalGroups = ["#"];
        }
        // Just in case a sub-product somehow gets visibility of #, we should propagate the lack of visibility
        if (count($finalGroups) > 1 && array_search("#", $finalGroups)) {
            $finalGroups = ["#"];
        }
        return [implode(",", $finalGroups)];
    }

    /**
     * Retrieve the default store_id, for inserting in place of static 1 from previous import versions
     * @return int
     */
    public function getDefaultStoreId(): int
    {
        if (empty($this->defaultStoreId)) {
            $storeView = $this->storeManager->getDefaultStoreView();
            if (!empty($storeView)) {
                $this->defaultStoreId = $storeView->getId();
            } else {
                $conn = $this->resourceConn->getConnection();
                $store = $conn->getTableName('store');
                $storeId = $conn->fetchCol(
                    "SELECT store_id FROM $store WHERE store_id != 0 AND code != 'admin' AND is_active = 1 ORDER BY sort_order LIMIT 1"
                );
                // Fallback to the old behaviour (store_id 1), if all else fails
                $this->defaultStoreId = (!empty($storeId) && is_numeric($storeId)) ? intval($storeId) : 1;
            }
        }
        return $this->defaultStoreId;
    }

    public function getSourceBySku(string $sku): string
    {
        $conn = $this->resourceConn->getConnection();
        $inventorySourceItem = $conn->getTableName('inventory_source_item');
        return $conn->fetchOne(
            "SELECT source_code from {$inventorySourceItem} WHERE sku = :sku ORDER BY quantity DESC LIMIT 1",
            [":sku" => $sku]
        );
    }
}
