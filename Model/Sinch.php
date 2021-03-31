<?php

namespace SITC\Sinchimport\Model;

require_once __DIR__ . '/Config.php';

use DateTime;
use Exception;
use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Processor;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Import\Attributes;
use SITC\Sinchimport\Model\Import\CustomCatalogVisibility;
use SITC\Sinchimport\Model\Import\CustomerGroupCategories;
use SITC\Sinchimport\Model\Import\CustomerGroupPrice;
use SITC\Sinchimport\Model\Import\IndexManagement;
use SITC\Sinchimport\Model\Import\StockPrice;
use SITC\Sinchimport\Model\Import\UNSPSC;
use SITC\Sinchimport\Model\Product\UrlFactory;
use Symfony\Component\Console\Output\ConsoleOutput;
use Zend_Db_Statement_Exception;
use Zend_Db_Statement_Interface;

class Sinch
{
    public $connection;
    public $varDir;
    public $files;
    public $debug_mode = false;

    /**
     * Application Event Dispatcher
     *
     * @var ManagerInterface
     */
    protected $_eventManager;

    /**
     * Store manager
     *
     * @var StoreManagerInterface
     */
    protected $_storeManager;
    protected $scopeConfig;

    /**
     * Logging instance
     *
     * @var Logger
     */
    protected $_sinchLogger;
    protected $_resourceConnection;
    protected $conn;

    /**
     * @var Processor
     */
    protected $_indexProcessor;

    /**
     * @var Pool
     */
    protected $_cacheFrontendPool;

    /**
     * Product url factory
     *
     * @var UrlFactory
     */
    protected $_productUrlFactory;

    /**
     * @var CollectionFactory
     */
    protected $indexersFactory;
    protected $_eavAttribute;
    private $output;
    private $galleryPhotos = [];
    private $_productEntityTypeId = 0;
    private $defaultAttributeSetId = 0;
    private $field_terminated_char;
    private $import_status_table;
    private $import_status_statistic_table;
    private $current_import_status_statistic_id;
    private $_attributeId;
    private $_categoryEntityTypeId;
    private $_categoryDefault_attribute_set_id;
    private $_rootCat;
    private $import_run_type = 'MANUAL';
    private $_ignore_category_features = false;
    private $_ignore_product_related = false;
    private $_ignore_product_contracts = false;
    private $_categoryMetaTitleAttrId;
    private $_categoryMetadescriptionAttrId;
    private $_categoryDescriptionAttrId;
    private $_dataConf;
    private $_deploymentData;
    private $imType;

    //Nick
    private $attributesImport;
    private $customerGroupCatsImport;
    private $customerGroupPrice;
    private $unspscImport;
    private $customCatalogImport;
    private $sitcIndexMgmt;
    private $dlHelper;
    private $stockPriceImport;

    public function __construct(
        Context $context,
        DirectoryList $directoryList,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        Logger $sinchLogger,
        ResourceConnection $resourceConnection,
        Processor $indexProcessor,
        Pool $cacheFrontendPool,
        DeploymentConfig $deploymentConfig,
        UrlFactory $productUrlFactory,
        CollectionFactory $indexersFactory,
        Attribute $eavAttribute,
        ConsoleOutput $output,
        Attributes $attributesImport,
        CustomerGroupCategories $customerGroupCatsImport,
        CustomerGroupPrice $customerGroupPrice,
        UNSPSC $unspscImport,
        CustomCatalogVisibility $customCatalogImport,
        IndexManagement $sitcIndexMgmt,
        Download $dlHelper,
        StockPrice $stockPriceImport
    )
    {
        $this->attributesImport = $attributesImport;
        $this->customerGroupCatsImport = $customerGroupCatsImport;
        $this->customerGroupPrice = $customerGroupPrice;
        $this->unspscImport = $unspscImport;
        $this->customCatalogImport = $customCatalogImport;
        $this->sitcIndexMgmt = $sitcIndexMgmt;
        $this->dlHelper = $dlHelper;
        $this->stockPriceImport = $stockPriceImport;

        $this->output = $output;
        $this->_storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->_sinchLogger = $sinchLogger->withName("SinchImport");
        $this->_resourceConnection = $resourceConnection;
        $this->_indexProcessor = $indexProcessor;
        $this->_cacheFrontendPool = $cacheFrontendPool;
        $this->_productUrlFactory = $productUrlFactory;
        $this->indexersFactory = $indexersFactory;
        $this->_eventManager = $context->getEventDispatcher();
        $this->conn = $this->_resourceConnection->getConnection();
        $this->_eavAttribute = $eavAttribute;

        $this->import_status_table = $this->getTableName('sinch_import_status');
        $this->import_status_statistic_table = $this->getTableName('sinch_import_status_statistic');

        $this->createTempDir($directoryList);

        $this->files = [
            FILE_CATEGORIES,
            FILE_CATEGORY_TYPES,
            FILE_CATEGORIES_FEATURES,
            FILE_DISTRIBUTORS,
            FILE_DISTRIBUTORS_STOCK_AND_PRICES,
            FILE_EANCODES,
            FILE_MANUFACTURERS,
            FILE_PRODUCT_FEATURES,
            FILE_PRODUCT_CATEGORIES,
            FILE_PRODUCTS,
            FILE_RELATED_PRODUCTS,
            FILE_RESTRICTED_VALUES,
            FILE_STOCK_AND_PRICES,
            FILE_PRODUCTS_PICTURES_GALLERY,
            FILE_PRICE_RULES,
            FILE_PRODUCT_CONTRACTS,
            FILE_CUSTOMER_GROUP_CATEGORIES,
            FILE_CUSTOMER_GROUPS,
            FILE_CUSTOMER_GROUP_PRICE
        ];

        $this->_dataConf = $this->scopeConfig->getValue(
            'sinchimport/sinch_ftp',
            ScopeInterface::SCOPE_STORE
        );

        $this->_deploymentData = $deploymentConfig->getConfigData();

        $this->field_terminated_char = DEFAULT_FILE_TERMINATED_CHAR;
    }

    private function getTableName(string $tableName): string
    {
        return $this->_resourceConnection->getTableName($tableName);
    }

    /**
     * Create the import directory Hierarchy
     *
     * @param DirectoryList $directoryList
     * @throws LocalizedException
     */
    private function createTempDir(DirectoryList $directoryList)
    {
        $dir = $directoryList->getPath(DirectoryList::VAR_DIR) . '/SITC/Sinchimport/';
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new LocalizedException(__("Failed to create import directory. Check filesystem permissions"));
            }
        }
        $this->varDir = $dir;
    }

    /**
     * @throws Exception
     */
    public function startCronFullImport()
    {
        $this->_log("Start full import from cron");

        $this->import_run_type = 'CRON';
        $this->runSinchImport();

        $this->_log("Finish full import from cron");
    }

    /**
     * Log some data to the sinch log file
     *
     * @param string $logString The message
     *
     * @return void
     */
    private function _log(string $logString)
    {
        $this->_sinchLogger->info($logString);
    }

    /**
     * @throws Exception
     */
    public function runSinchImport()
    {
        $indexingSeparately = $this->scopeConfig->getValue(
            'sinchimport/sinch_import_fullstatus/indexing_separately',
            ScopeInterface::SCOPE_STORE
        );

        $this->_categoryMetaTitleAttrId = $this->_getCategoryAttributeId(
            'meta_title'
        );
        $this->_categoryMetadescriptionAttrId = $this->_getCategoryAttributeId(
            'meta_description'
        );
        $this->_categoryDescriptionAttrId = $this->_getCategoryAttributeId(
            'description'
        );

        $this->initImportStatuses('FULL');

        $file_privileg = $this->checkDbPrivileges();
        if (!$file_privileg) {
            $this->_setErrorMessage(
                "LOAD DATA option not set"
            );
            throw new LocalizedException(__("LOAD DATA option not enabled in database"));
        }

        $local_infile = $this->checkLocalInFile();
        if (!$local_infile) {
            $this->_setErrorMessage(
                "LOCAL INFILE is not enabled in the database"
            );
            throw new LocalizedException(__("LOCAL INFILE is not enabled in the database"));
        }

        if ($this->isImportNotRun()) {
            $current_vhost = $this->scopeConfig->getValue(
                'web/unsecure/base_url',
                ScopeInterface::SCOPE_STORE
            );
            try {
                $imType = $this->_dataConf['replace_category'];

                $this->_doQuery("SELECT GET_LOCK('sinchimport_{$current_vhost}', 30)");

                //Once we hold the import lock, check/await indexer completion
                $this->print("Making sure no indexers are currently running");
                if (!$this->sitcIndexMgmt->ensureIndexersNotRunning()) {
                    $this->print("There are indexers currently running, abandoning import");
                    $this->_setErrorMessage("There are indexers currently running, abandoning import");
                    throw new LocalizedException(__("There are indexers currently running, abandoning import"));
                }

                $this->addImportStatus('Start Import');

                $this->print("========IMPORTING DATA IN $imType MODE========");

                $this->print("Upload Files...");
                $this->downloadFiles();
                $this->addImportStatus('Upload Files');

                $this->print("Parse Category Types...");
                $this->parseCategoryTypes();

                $this->print("Parse Categories...");
                $coincidence = $this->parseCategories();
                $this->addImportStatus('Parse Categories');

                $this->print("Parse Category Features...");
                $this->parseCategoryFeatures();
                $this->addImportStatus('Parse Category Features');

                $this->print("Parse Distributors...");
                $this->stockPriceImport->parse(
                    $this->varDir . FILE_STOCK_AND_PRICES,
                    $this->varDir . FILE_DISTRIBUTORS,
                    $this->varDir . FILE_DISTRIBUTORS_STOCK_AND_PRICES
                );
                $this->parseProductContracts();
                $this->addImportStatus('Parse Distributors');

                $this->print("Parse EAN Codes...");
                $this->parseEANCodes();
                $this->addImportStatus('Parse EAN Codes');

                $this->print("Parse Manufacturers...");
                $this->parseManufacturers();
                $this->addImportStatus('Parse Manufacturers');

                $this->print("Parse Related Products...");
                $this->parseRelatedProducts();
                $this->addImportStatus('Parse Related Products');

                $this->print("Parse Product Features...");
                $this->attributesImport->parse(
                    $this->varDir . FILE_CATEGORIES_FEATURES,
                    $this->varDir . FILE_RESTRICTED_VALUES,
                    $this->varDir . FILE_PRODUCT_FEATURES
                );
                $this->addImportStatus('Parse Product Features');

                $this->print("Parse Product Categories...");
                $this->parseProductCategories();

                $this->print("Parse Products...");
                $this->parseProducts($coincidence);
                $this->addImportStatus('Parse Products');

                $this->print("Parse Pictures Gallery...");
                $this->parseProductsPicturesGallery();
                $this->addImportStatus('Parse Pictures Gallery');

                $this->print("Parse Restricted Values...");
                $this->attributesImport->applyAttributeValues();
                $this->addImportStatus('Parse Restricted Values');

                $this->print("Parse Stock And Prices...");
                //Replaced parseStockAndPrices
                $this->stockPriceImport->apply();
                $this->addImportStatus('Parse Stock And Prices');

                $this->print("Apply Customer Group Price...");
                if (file_exists($this->varDir . FILE_CUSTOMER_GROUPS) &&
                    file_exists($this->varDir . FILE_CUSTOMER_GROUP_PRICE)) {
                    $this->customerGroupPrice->parse(
                        $this->varDir . FILE_CUSTOMER_GROUPS,
                        $this->varDir . FILE_CUSTOMER_GROUP_PRICE
                    );
                }

                if (file_exists($this->varDir . FILE_PRICE_RULES)) {
                    $this->_eventManager->dispatch(
                        'sinch_pricerules_import_ftp',
                        [
                            'ftp_host' => $this->_dataConf["ftp_server"],
                            'ftp_username' => $this->_dataConf["username"],
                            'ftp_password' => $this->_dataConf["password"]
                        ]
                    );
                }

                //Allow the CC category visibility import section to be skipped
                $ccCategoryDisable = $this->scopeConfig->getValue(
                    'sinchimport/category_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccCategoryDisable) {
                    if (file_exists($this->varDir . FILE_CUSTOMER_GROUP_CATEGORIES)) {
                        $this->print("Parsing customer group categories...");
                        $this->customerGroupCatsImport->parse($this->varDir . FILE_CUSTOMER_GROUP_CATEGORIES);
                    }
                } else {
                    $this->print("Skipping custom catalog categories as 'sinchimport/category_visibility/disable_import' is enabled");
                }

                $this->print("Applying UNSPSC values...");
                $this->unspscImport->apply();

                //Allow the CC product visibility import section to be skipped
                $ccProductDisable = $this->scopeConfig->getValue(
                    'sinchimport/product_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccProductDisable) {
                    if (file_exists($this->varDir . FILE_CUSTOMER_GROUP_PRICE) && file_exists($this->varDir . FILE_STOCK_AND_PRICES)) {
                        $this->print("Processing Custom catalog restrictions...");
                        $this->customCatalogImport->parse(
                            $this->varDir . FILE_STOCK_AND_PRICES,
                            $this->varDir . FILE_CUSTOMER_GROUP_PRICE
                        );
                    }
                } else {
                    $this->print("Skipping custom catalog restrictions as 'sinchimport/product_visibility/disable_import' is enabled");
                }

                $this->print("Start generating category filters...");
                $this->addImportStatus('Generate category filters');
                $this->print("Finish generating category filters...");

                try {
                    $this->print("Running post import hooks");
                    $this->_eventManager->dispatch(
                        'sinchimport_post_import',
                        [
                            'import_type' => 'FULL'
                        ]
                    );
                    $this->print("Post import hooks complete");
                } catch (Exception $e) {
                    $this->print("Caught exception while running post import hooks: " . $e->getMessage());
                }

                if (!$indexingSeparately) {
                    $this->print("Start indexing data...");
                    $this->_cleanCateoryProductFlatTable();
                    $this->runIndexer();
                } else {
                    $this->print("Bypass indexing data...");
                    $this->invalidateIndexers();
                }

                $this->print("Start indexing catalog url rewrites...");
                $this->_reindexProductUrlKey();
                $this->print("Finish indexing catalog url rewrites...");

                $this->addImportStatus('Indexing data');
                $this->print("Finish indexing data...");

                $this->print("Start cleaning Sinch cache...");
                $this->runCleanCache();
                $this->print("Finish cleaning Sinch cache...");

                $this->addImportStatus('Finish import', 1);


                $this->print("========>FINISH SINCH IMPORT...");
            } catch (Exception $e) {
                $this->_setErrorMessage($e);
                $this->print("Error (" . gettype($e) . "):" . $e->getMessage());
            } finally {
                $this->_doQuery("SELECT RELEASE_LOCK('sinchimport_{$current_vhost}')");
            }
        } else {
            $this->print("--------SINCHIMPORT ALREADY RUN--------");
        }
    }

    private function _getCategoryAttributeId($attributeCode)
    {
        return $this->_getAttributeId($attributeCode, 'catalog_category');
    }

    private function _getAttributeId($attributeCode, $typeCode)
    {
        if ($typeCode == 'catalog_product') {
            $typeId = $this->_getProductEntityTypeId();
        } else {
            $typeId = $this->_getEntityTypeId($typeCode);
        }

        if (!isset($this->_attributeId[$typeCode]) or !is_array($this->_attributeId[$typeCode])) {
            $eav_attribute = $this->getTableName('eav_attribute');
            $result = $this->conn->fetchAll(
                "SELECT attribute_id, attribute_code FROM $eav_attribute WHERE entity_type_id = :typeId",
                [":typeId" => $typeId]
            );

            foreach ($result as $resultItem) {
                $this->_attributeId[$typeCode][$resultItem['attribute_code']] = $resultItem['attribute_id'];
            }
        }

        return $this->_attributeId[$typeCode][$attributeCode];
    }

    private function _getProductEntityTypeId()
    {
        if (!$this->_productEntityTypeId) {
            $this->_productEntityTypeId = $this->_getEntityTypeId('catalog_product');
        }
        return $this->_productEntityTypeId;
    }

    private function _getEntityTypeId($code)
    {
        $eav_entity_type = $this->getTableName('eav_entity_type');
        $result = $this->conn->fetchOne(
            "SELECT entity_type_id FROM $eav_entity_type WHERE entity_type_code = :code LIMIT 1",
            [":code" => $code]
        );

        if (is_numeric($result)) {
            return (int)$result;
        }

        return false;
    }

    private function initImportStatuses($type)
    {
        $this->_doQuery("DROP TABLE IF EXISTS {$this->import_status_table}");
        $this->_doQuery(
            "CREATE TABLE {$this->import_status_table} (
                id int(11) NOT NULL auto_increment PRIMARY KEY,
                message varchar(50),
                finished int(1) default 0
            )"
        );

        $scheduledImportId = false;
        if ($this->import_run_type == 'MANUAL') {
            $scheduledImportId = $this->conn->fetchOne(
                "SELECT id FROM {$this->import_status_statistic_table}
                    WHERE import_run_type = 'MANUAL'
                    AND global_status_import = 'Scheduled'
                    ORDER BY start_import DESC
                    LIMIT 1"
            );
        }

        if (empty($scheduledImportId)) {
            $this->conn->query(
                "INSERT INTO {$this->import_status_statistic_table} (
                    start_import,
                    finish_import,
                    import_type,
                    global_status_import,
                    import_run_type,
                    error_report_message
                ) VALUES(
                    NOW(),
                    '0000-00-00 00:00:00',
                    :importType,
                    'Run',
                    :runType,
                    ''
                )",
                [
                    ":importType" => $type,
                    ":runType" => $this->import_run_type
                ]
            );

            $result = $this->conn->fetchOne("SELECT MAX(id) FROM {$this->import_status_statistic_table}");
            $this->current_import_status_statistic_id = is_numeric($result) ? (int)$result : 0;
        } else {
            $this->conn->query(
                "UPDATE {$this->import_status_statistic_table}
                    SET global_status_import = 'Run'
                    WHERE id = :id",
                [":id" => $scheduledImportId]
            );

            $this->current_import_status_statistic_id = $scheduledImportId;
        }

        $this->conn->query(
            "UPDATE {$this->import_status_statistic_table}
                SET global_status_import = 'Failed'
                WHERE (global_status_import = 'Run' OR global_status_import = 'Scheduled')
                AND id != :id",
            [":id" => $this->current_import_status_statistic_id]
        );
    }

    private function _doQuery($query, $binds = [], $forceStopLogging = false): Zend_Db_Statement_Interface
    {
        if ($this->debug_mode && !$forceStopLogging) {
            $this->_log("Query: " . $query);
        }

        return $this->conn->query($query, $binds);
    }

    private function checkDbPrivileges(): bool
    {
        return true;
    }

    /**
     * Set the error message for the import
     * including logging it
     *
     * @param string $message The error message
     *
     * @return void
     */
    private function _setErrorMessage(string $message)
    {
        $this->_log($message);
        $this->conn->query(
            "UPDATE {$this->import_status_statistic_table} SET error_report_message = :msg WHERE id = :importId",
            [
                ":importId" => $this->current_import_status_statistic_id,
                ":msg" => $message
            ]
        );
    }

    private function checkLocalInFile(): bool
    {
        $result = $this->_doQuery("SHOW VARIABLES LIKE 'local_infile'")->fetch();

        return $result['Variable_name'] == 'local_infile' && $result['Value'] == "ON";
    }

    public function isImportNotRun(): bool
    {
        $current_vhost = $this->scopeConfig->getValue(
            'web/unsecure/base_url',
            ScopeInterface::SCOPE_STORE
        );
        $result = $this->_doQuery("SELECT IS_FREE_LOCK('sinchimport_{$current_vhost}') as getlock")->fetch();

        $this->_log('GET SINCH IMPORT LOG: ' . $result['getlock']);

        if (!$result['getlock']) {
            $lastImportData = $this->getDataOfLatestImport();
            if (!empty($lastImportData)) {
                $startTime = strtotime($lastImportData['start_import']);
                $now = (new DateTime())->getTimestamp();
                $interval = abs($now - $startTime);
                $this->_log('DIFF TIME: ' . $interval);
                if ($interval > 10800) {
                    return true;
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    public function getDataOfLatestImport()
    {
        return $this->conn->fetchRow(
            "SELECT
                start_import,
                finish_import,
                import_type,
                number_of_products,
                global_status_import,
                detail_status_import,
                number_of_products,
                error_report_message
            FROM {$this->import_status_statistic_table}
            ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Print a message to the console
     *
     * @param string $message The message
     */
    private function print(string $message)
    {
        $this->output->writeln($message);
        $this->_log($message);
    }

    private function addImportStatus($message, $finished = 0)
    {
        $this->conn->query(
            "INSERT INTO {$this->import_status_table} (message, finished)
                    VALUES(:msg, :finished)",
            [":msg" => $message, ":finished" => $finished]
        );
        $this->conn->query(
            "UPDATE {$this->import_status_statistic_table} SET detail_status_import = :msg WHERE id = :importId",
            [":importId" => $this->current_import_status_statistic_id, ":msg" => $message]
        );
        if ($finished == 1) {
            $this->conn->query(
                "UPDATE {$this->import_status_statistic_table}
                        SET global_status_import = 'Successful',
                            finish_import = NOW()
                        WHERE error_report_message = '' AND id = :importId",
                [":importId" => $this->current_import_status_statistic_id]
            );
        }
    }

    private function downloadFiles()
    {
        $this->_log("--- Start downloading files ---");

        $connRes = $this->dlHelper->connect();
        if ($connRes !== true) {
            $this->_setErrorMessage($connRes);
            throw new LocalizedException(__($connRes));
        }

        try {
            foreach ($this->files as $file) {
                $dlRes = $this->dlHelper->downloadFile($file);
                if (!$dlRes || !file_exists($this->varDir . $file) || @filesize($this->varDir . $file) < 1) {
                    //Allow failures of optional files
                    switch ($file) {
                        case FILE_CATEGORIES_FEATURES:
                            $this->_ignore_category_features = true;
                            break;
                        case FILE_CATEGORY_TYPES:
                        case FILE_CUSTOMER_GROUP_CATEGORIES:
                        case FILE_DISTRIBUTORS_STOCK_AND_PRICES:
                        case FILE_RESTRICTED_VALUES:
                        case FILE_PRICE_RULES:
                        case FILE_PRODUCT_FEATURES:
                            break;
                        case FILE_RELATED_PRODUCTS:
                            $this->_ignore_product_related = true;
                            break;
                        case FILE_PRODUCT_CONTRACTS:
                            $this->_ignore_product_contracts = true;
                            break;
                        default:
                            $this->_setErrorMessage("$file is empty, cannot continue");
                            throw new LocalizedException(__("$file is empty, cannot continue"));
                    }
                    $this->print("Failed to download optional file $file, skipping");
                }
            }
        } finally {
            $this->dlHelper->disconnect();
        }
        $this->_log("--- Finished downloading files ---");
    }

    private function parseCategoryTypes()
    {
        $parseFile = $this->varDir . FILE_CATEGORY_TYPES;
        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_CATEGORY_TYPES);

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'category_types_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('category_types_temp') . "(
                          id int(11),
                          name varchar(255),
                          key(id)
                          )"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName(
                    'category_types_temp'
                ) . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char
                . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'sinch_category_types'
                )
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('category_types_temp') . "
                          TO " . $this->getTableName('sinch_category_types')
            );

            $this->_log("Finish parse " . FILE_CATEGORY_TYPES);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    /**
     * @return array Root category names, as determined by RootName
     * @throws LocalizedException
     */
    private function parseCategories(): array
    {
        $imType = $this->_dataConf['replace_category'];
        $parseFile = $this->varDir . FILE_CATEGORIES;
        //$parseFile = $this->varDir . FILE_CATEGORIES_TEST;
        $field_terminated_char = $this->field_terminated_char;

        $this->imType = $imType;

        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_CATEGORIES);

            $this->_getCategoryEntityTypeIdAndDefault_attribute_set_id();

            $categories_temp = $this->getTableName(
                'categories_temp'
            );

            $_categoryDefault_attribute_set_id
                = $this->_categoryDefault_attribute_set_id;

            $name_attrid = $this->_getCategoryAttributeId('name');
            $is_anchor_attrid = $this->_getCategoryAttributeId('is_anchor');
            $image_attrid = $this->_getCategoryAttributeId('image');

            $attr_url_key = $this->_getCategoryAttributeId('url_key');
            $attr_display_mode = $this->_getCategoryAttributeId(
                'display_mode'
            );
            $attr_is_active = $this->_getCategoryAttributeId('is_active');
            $attr_include_in_menu = $this->_getCategoryAttributeId(
                'include_in_menu'
            );

            $this->loadCategoriesTemp(
                $categories_temp,
                $parseFile,
                $field_terminated_char
            );
            $rootCatNames = $this->getDistinctRootCatNames();

            if (!$this->check_loaded_data($parseFile, $categories_temp)) {
                $this->_setErrorMessage(
                    'The Stock In The Channel data files do not appear to be in the correct format. Check file'
                    . $parseFile
                );
                throw new LocalizedException(__("Import files in invalid format"));
            }

            if (count($rootCatNames) == 1) { // one store logic

                //TODO: Should single store logic be removed in favour of a multistore version that robustly handles single store too?
                $this->print("==========SINGLE STORE LOGIC==========");

                if ($imType == "REWRITE") {
                    $rootCat = 2;

                    $rootCat = $this->truncateAllCategoriesAndRecreateDefaults(
                        $rootCat,
                        $_categoryDefault_attribute_set_id,
                        $name_attrid,
                        $attr_url_key,
                        $attr_display_mode,
                        $attr_is_active,
                        $attr_include_in_menu
                    );
                } else {
                    $rootCat = $this->_getShopRootCategoryId();
                }

                $this->_rootCat = $rootCat;

                $this->setCategorySettings($rootCat);
                $this->mapSinchCategories($imType, $name_attrid);
                $this->addCategoryData(
                    $_categoryDefault_attribute_set_id,
                    $name_attrid,
                    $attr_is_active,
                    $attr_include_in_menu,
                    $is_anchor_attrid,
                    $image_attrid,
                    $imType
                );
            } elseif (count($rootCatNames) > 1) { // multistore logic

                $this->print("==========MULTI STORE LOGIC==========");

                switch ($imType) {
                    case "REWRITE":
                        $this->rewriteMultistoreCategories(
                            $rootCatNames,
                            $_categoryDefault_attribute_set_id,
                            $imType,
                            $name_attrid,
                            $attr_display_mode,
                            $attr_url_key,
                            $attr_include_in_menu,
                            $attr_is_active,
                            $image_attrid,
                            $is_anchor_attrid
                        );
                        break;
                    case "MERGE":
                        $this->mergeMultistoreCategories(
                            $rootCatNames,
                            $_categoryDefault_attribute_set_id,
                            $imType,
                            $name_attrid,
                            $attr_display_mode,
                            $attr_url_key,
                            $attr_include_in_menu,
                            $attr_is_active,
                            $image_attrid,
                            $is_anchor_attrid
                        );
                        break;
                    default:
                        // do anything
                }
            } else {
                $this->print("====================>ERROR");
            }

            $this->_log("Finish parse " . FILE_CATEGORIES);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
        $this->_log(' ');
        $this->_set_default_rootCategory();

        return $rootCatNames;
    }

    private function _getCategoryEntityTypeIdAndDefault_attribute_set_id()
    {
        if (!$this->_categoryEntityTypeId
            || !$this->_categoryDefault_attribute_set_id
        ) {
            $sql
                = "
                    SELECT entity_type_id, default_attribute_set_id
                    FROM " . $this->getTableName('eav_entity_type') . "
                    WHERE entity_type_code = 'catalog_category'
                    LIMIT 1
                   ";
            $result = $this->_doQuery($sql)->fetch();
            if ($result) {
                $this->_categoryEntityTypeId = $result['entity_type_id'];
                $this->_categoryDefault_attribute_set_id
                    = $result['default_attribute_set_id'];
            }
        }
    }

    private function loadCategoriesTemp(
        $categories_temp,
        $parseFile,
        $field_terminated_char
    )
    {
        $this->_doQuery("DROP TABLE IF EXISTS $categories_temp");

        $this->_doQuery(
            "
            CREATE TABLE $categories_temp
                (
                    store_category_id              INT(11),
                    parent_store_category_id       INT(11),
                    category_name                  VARCHAR(50),
                    order_number                   INT(11),
                    is_hidden                      VARCHAR(10),
                    products_within_sub_categories INT(11),
                    products_within_this_category  INT(11),
                    categories_image               VARCHAR(255),
                    level                          INT(10) NOT NULL DEFAULT 0,
                    children_count                 INT(11) NOT NULL DEFAULT 0,
                    UNSPSC                         INT(10) DEFAULT NULL,
                    RootName                       INT(10) DEFAULT NULL,
                    MainImageURL                   VARCHAR(255),
                    MetaTitle                      TEXT,
                    MetaDescription                TEXT,
                    Description                    TEXT,
                    KEY(store_category_id),
                    KEY(parent_store_category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );

        $this->_doQuery(
            "
            LOAD DATA LOCAL INFILE '$parseFile' INTO TABLE $categories_temp
            FIELDS TERMINATED BY '$field_terminated_char' OPTIONALLY ENCLOSED BY '\"' LINES TERMINATED BY \"\r\n\" IGNORE 1 LINES"
        );

        $this->_doQuery(
            "ALTER TABLE $categories_temp ADD COLUMN include_in_menu TINYINT(1) NOT NULL DEFAULT 1"
        );
        $this->_doQuery(
            "UPDATE $categories_temp SET include_in_menu = 0 WHERE UCASE(is_hidden)='TRUE'"
        );

        $this->_doQuery(
            "ALTER TABLE $categories_temp ADD COLUMN is_anchor TINYINT(1) NOT NULL DEFAULT 1"
        );
        $this->_doQuery(
            "UPDATE $categories_temp SET level = (level+2) WHERE level >= 0"
        );
    }

    private function getDistinctRootCatNames(): array
    {
        $categories_temp = $this->getTableName('categories_temp');

        $newRootCat = $this->_doQuery(
            "SELECT DISTINCT RootName FROM $categories_temp"
        )->fetchAll();

        $existsCoincidence = [];

        foreach ($newRootCat as $rootCat) {
            $existsCoincidence[] = $rootCat['RootName'];
        }

        return $existsCoincidence;
    }

    private function check_loaded_data($file, $table): bool
    {
        $cnt_strings_in_file = $this->file_strings_count($file);
        $cnt_rows_int_table = $this->table_rows_count($table);
        $persent_cnt_strings_in_file = $cnt_strings_in_file / 10;
        if ($cnt_rows_int_table > $persent_cnt_strings_in_file) {
            return true;
        } else {
            return false;
        }
    }

    private function file_strings_count($parseFile): int
    {
        return count(file($parseFile));
    }

    private function table_rows_count($table): int
    {
        return (int)$this->conn->fetchOne(
            "SELECT COUNT(*) FROM $table"
        );
    }

    //TODO: Remove pointless attribute ids passed as args
    private function truncateAllCategoriesAndRecreateDefaults(
        $rootCat,
        $_categoryDefault_attribute_set_id,
        $name_attrid,
        $attr_url_key,
        $attr_display_mode,
        $attr_is_active,
        $attr_include_in_menu
    )
    {
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');

        $this->_doQuery("DELETE FROM $catalog_category_entity");

        $this->_doQuery(
            "
                    INSERT $catalog_category_entity
                        (
                            entity_id,
                            attribute_set_id,
                            parent_id,
                            created_at,
                            updated_at,
                            path,
                            position,
                            level,
                            children_count,
                            store_category_id,
                            parent_store_category_id
                        )
                    VALUES
                                (1, $_categoryDefault_attribute_set_id, 0, '0000-00-00 00:00:00', now(), '1', 0, 0, 1, null, null),
                                (2, $_categoryDefault_attribute_set_id, 1, now(), now(), '1/2', 1, 1, 1, null, null)"
        );
        $this->_doQuery(
            "
                    INSERT $catalog_category_entity_varchar
                        (
                            value_id,
                            attribute_id,
                            store_id,
                            entity_id,
                            value
                        )
                    VALUES
                        (1, $name_attrid, 0, 1, 'Root Catalog'),
                        (2, $name_attrid, 1, 1, 'Root Catalog'),
                        (3, $attr_url_key, 0, 1, 'root-catalog'),
                        (4, $name_attrid, 0, 2, 'Default Category'),
                        (5, $name_attrid, 1, 2, 'Default Category'),
                        (6, $attr_display_mode, 1, 2, 'PRODUCTS'),
                        (7, $attr_url_key, 0, 2, 'default-category')"
        );
        $this->_doQuery(
            "
                    INSERT $catalog_category_entity_int
                        (
                            value_id,
                            attribute_id,
                            store_id,
                            entity_id,
                            value
                        )
                    VALUES
                        (1, $attr_is_active, 0, 2, 1),
                        (2, $attr_is_active, 1, 2, 1),
                        (3, $attr_include_in_menu, 0, 1, 1),
                        (4, $attr_include_in_menu, 0, 2, 1)"
        );

        return $rootCat;
    }

    private function _getShopRootCategoryId($cat_id = 0): int
    {
        if ($rootCat = $this->_storeManager->getStore()->getRootCategoryId()) {
            return $rootCat;
        } else {
            //The url_key attribute with value 'default-category' can tell us which is the default category
            $res = $this->conn->fetchOne(
                "SELECT entity_id FROM " . $this->getTableName('catalog_category_entity_varchar') . " WHERE value = 'default-category'"
            );
            if (is_numeric($res)) {
                return (int)$res;
            } else {
                $statement = $this->_doQuery(
                    "SELECT entity_id FROM " . $this->getTableName('catalog_category_entity') . " WHERE parent_id = :parent",
                    [":parent" => $cat_id]
                );

                if ($statement->rowCount() != 1) {
                    return $cat_id;
                }

                $entity_id = (int)$statement->fetchColumn();
                $statement->closeCursor();

                //Recurse until hitting a category with != 1 child
                return $this->_getShopRootCategoryId($entity_id);
            }
        }
    }

    private function setCategorySettings($rootCat)
    {
        $categories_temp = $this->getTableName('categories_temp');

        $this->_doQuery(
            "UPDATE $categories_temp
            SET parent_store_category_id = :root
            WHERE parent_store_category_id = 0",
            [":root" => $rootCat]
        );

        $storeCatIds = $this->conn->fetchCol("SELECT store_category_id FROM $categories_temp");

        foreach ($storeCatIds as $storeCategory) {
            $children_count = $this->countChildCategories($storeCategory);
            $level = $this->getCategoryDepth($storeCategory);

            $this->_doQuery(
                "UPDATE $categories_temp
                    SET children_count = :childCount, level = :level
                    WHERE store_category_id = :catId",
                [
                    ":catId" => $storeCategory,
                    ":childCount" => $children_count,
                    ":level" => $level
                ]
            );
        }
    }

    private function countChildCategories($sinch_category_id): int
    {
        $categories_temp = $this->getTableName('categories_temp');
        $childCategories = $this->conn->fetchCol(
            "SELECT store_category_id FROM {$categories_temp} WHERE parent_store_category_id = :parent",
            [":parent" => $sinch_category_id]
        );

        $count = count($childCategories);
        foreach ($childCategories as $childCat) {
            $count += $this->countChildCategories($childCat);
        }

        return $count;
    }

    private function getCategoryDepth($sinch_category_id): int
    {
        $conn = $this->_resourceConnection->getConnection();

        $catTemp = $this->getTableName('categories_temp');
        $parentCat = $conn->fetchOne(
            "SELECT parent_store_category_id FROM {$catTemp} WHERE store_category_id = :storeCategoryId",
            [":storeCategoryId" => $sinch_category_id]
        );

        $level = 1;

        while (is_numeric($parentCat) && $parentCat != 0) {
            $parentCat = $conn->fetchOne(
                "SELECT parent_store_category_id FROM {$catTemp} WHERE store_category_id = :storeCategoryId",
                [":storeCategoryId" => $parentCat]
            );

            $level++;

            if ($level > 20) {
                break;
            }
        }

        return $level;
    }

    private function tableHasData($table): bool
    {
        $tableRowCount = $this->_doQuery(
            "SELECT *
            FROM $table"
        )->rowCount();

        return $tableRowCount > 0;
    }

    private function calculateCategoryPath($parent_id, $ent_id): string
    {
        $path = '';
        $cat_id = $parent_id;

        $conn = $this->_resourceConnection->getConnection();
        $catalog_category_entity = $this->getTableName('catalog_category_entity');

        $parentCat = $conn->fetchOne(
            "SELECT parent_id FROM {$catalog_category_entity} WHERE entity_id = :catId",
            [":catId" => $cat_id]
        );

        //Must avoid 0 otherwise we get a completely fucked category tree
        //If that happens categories only turn up as search filters
        while (is_numeric($parentCat) && $parentCat != 0) {
            $path = $parentCat . '/' . $path;
            $parentCat = $conn->fetchOne(
                "SELECT parent_id FROM {$catalog_category_entity} WHERE entity_id = :catId",
                [":catId" => $parentCat]
            );
        }
        if ($cat_id) {
            $path .= $cat_id . "/";
        }

        if ($path) {
            return $path . $ent_id;
        } else {
            return $ent_id;
        }
    }

    private function deleteOldSinchCategories()
    {
        $cce = $this->getTableName('catalog_category_entity');
        $ccev = $this->getTableName('catalog_category_entity_varchar');
        $ccei = $this->getTableName('catalog_category_entity_int');
        $scm = $this->getTableName('sinch_categories_mapping');

        $this->_doQuery(
            "DELETE ccev FROM $ccev ccev
            JOIN $scm scm
                ON ccev.entity_id = scm.shop_entity_id
            WHERE
                (scm.shop_store_category_id IS NOT NULL) AND
                (scm.store_category_id IS NULL)"
        );

        $this->_doQuery(
            "DELETE ccei FROM $ccei ccei
            JOIN $scm scm
                ON ccei.entity_id = scm.shop_entity_id
            WHERE
                (scm.shop_store_category_id IS NOT NULL) AND
                (scm.store_category_id IS NULL)"
        );

        $this->_doQuery(
            "DELETE cce FROM $cce cce
            JOIN $scm scm
                ON cce.entity_id = scm.shop_entity_id
            WHERE
                (scm.shop_store_category_id IS NOT NULL) AND
                (scm.store_category_id IS NULL)"
        );
    }

    //TODO: Remove pointless attribute ids passed as args
    private function rewriteMultistoreCategories(
        $coincidence,
        $_categoryDefault_attribute_set_id,
        $imType,
        $name_attrid,
        $attr_display_mode,
        $attr_url_key,
        $attr_include_in_menu,
        $attr_is_active,
        $image_attrid,
        $is_anchor_attrid
    )
    {

        $this->print("Rewrite Categories...");

        $this->print("    --Truncate all categories...");
        $this->truncateCategoriesAndCreateRoot(
            $_categoryDefault_attribute_set_id,
            $name_attrid,
            $attr_url_key,
            $attr_include_in_menu
        );

        $this->print("    --Create default categories...");
        $this->createDefaultCategories(
            $coincidence,
            $_categoryDefault_attribute_set_id,
            $name_attrid,
            $attr_display_mode,
            $attr_url_key,
            $attr_is_active,
            $attr_include_in_menu
        );

        $this->print("    --Map SINCH categories...");
        $this->mapSinchCategories(
            $imType,
            $name_attrid
        );

        $this->print("    --Add category data...");
        $this->addCategoryData(
            $_categoryDefault_attribute_set_id,
            $imType,
            $name_attrid,
            $attr_is_active,
            $attr_include_in_menu,
            $is_anchor_attrid,
            $image_attrid
        );
    }

    //TODO: Remove pointless attribute ids passed as args
    private function truncateCategoriesAndCreateRoot(
        $_categoryDefault_attribute_set_id,
        $name_attrid,
        $attr_url_key,
        $attr_include_in_menu
    )
    {
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');

        $this->_doQuery("DELETE FROM $catalog_category_entity");

        $this->_doQuery(
            "INSERT $catalog_category_entity
                    (entity_id, attribute_set_id, parent_id, created_at, updated_at, path, position, level, children_count)
                VALUES
                    (1, :attrSet, 0, '0000-00-00 00:00:00', NOW(), '1', 0, 0, 1)",
            [":attrSet" => $_categoryDefault_attribute_set_id]
        );

        $this->_doQuery(
            "INSERT $catalog_category_entity_varchar
                    (value_id, attribute_id, store_id, entity_id, value)
                VALUES
                    (1, :nameAttr, 0, 1, 'Root Catalog'),
                    (2, :nameAttr, 1, 1, 'Root Catalog'),
                    (3, :urlKeyAttr, 0, 1, 'root-catalog')",
            [":nameAttr" => $name_attrid, ":urlKeyAttr" => $attr_url_key]
        );

        $this->_doQuery(
            "INSERT $catalog_category_entity_int
                    (value_id, attribute_id, store_id, entity_id, value)
                VALUES
                    (1, :includeInMenuAttr, 0, 1, 1)",
            [":includeInMenuAttr" => $attr_include_in_menu]
        );
    }

    //TODO: Remove pointless attribute ids passed as args
    private function createDefaultCategories(
        $coincidence, //TODO: Actually an array of category names?
        $_categoryDefault_attribute_set_id,
        $name_attrid,
        $attr_display_mode,
        $attr_url_key,
        $attr_is_active,
        $attr_include_in_menu
    )
    {
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');

        $i = 3; // 2 - is Default Category... not use.

        foreach ($coincidence as $key) {
            $this->_doQuery(
                "INSERT $catalog_category_entity
                        (entity_id, attribute_set_id, parent_id, created_at, updated_at, path, position, level, children_count)
                    VALUES
                        (:entityId, :attrSet, 1, NOW(), NOW(), :path, 1, 1, 1)",
                [
                    ":entityId" => $i,
                    ":attrSet" => $_categoryDefault_attribute_set_id,
                    ":path" => "1/$i"
                ]
            );

            $this->_doQuery(
                "INSERT $catalog_category_entity_varchar
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        (:nameAttr,       0, :entityId, :value),
                        (:nameAttr,       1, :entityId, :value),
                        (:displayModeAttr, 1, :entityId, :value),
                        (:urlKeyAttr,      0, :entityId, :value)",
                [
                    ":nameAttr" => $name_attrid,
                    ":displayModeAttr" => $attr_display_mode,
                    ":urlKeyAttr" => $attr_url_key,
                    ":entityId" => $i,
                    ":value" => "$key"
                ] //TODO: Why is value inserted into display_mode?
            );

            $this->_doQuery(
                "INSERT $catalog_category_entity_int
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        (:isActiveAttr, 0, :entityId, 1),
                        (:isActiveAttr, 1, :entityId, 1),
                        (:includeInMenuAttr, 0, :entityId, 1),
                        (:includeInMenuAttr, 1, :entityId, 1)",
                [
                    ":isActiveAttr" => $attr_is_active,
                    ":includeInMenuAttr" => $attr_include_in_menu,
                    ":entityId" => $i
                ]
            );
            $i++;
        }
    }

    //TODO: Remove pointless attribute ids passed as args
    private function mapSinchCategories($imType, $name_attrid, $mapping_again = false)
    {
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $sinch_categories_mapping_temp = $this->getTableName('sinch_categories_mapping_temp');
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $categories_temp = $this->getTableName('categories_temp');

        $this->createMappingSinchTables();

        $rootCategories = $this->conn->fetchAll(
            "SELECT DISTINCT
                        c.RootName, cce.entity_id
                    FROM $categories_temp c
                    JOIN $catalog_category_entity_varchar ccev
                        ON c.RootName = ccev.value
                        AND ccev.attribute_id = $name_attrid
                        AND ccev.store_id = 0
                    JOIN $catalog_category_entity cce
                        ON ccev.entity_id = cce.entity_id"
        );

        // backup Category ID in REWRITE mode
        if ($imType == "REWRITE" || (UPDATE_CATEGORY_DATA && $imType == "MERGE")) {
            if ($mapping_again) {
                $this->_doQuery(
                    "INSERT IGNORE INTO $sinch_categories_mapping_temp
                        (
                            shop_entity_id,
                            shop_attribute_set_id,
                            shop_parent_id,
                            shop_store_category_id,
                            shop_parent_store_category_id
                        )
                    (SELECT
                        entity_id,
                        attribute_set_id,
                        parent_id,
                        store_category_id,
                        parent_store_category_id
                    FROM $catalog_category_entity)"
                );

                $this->_doQuery(
                    "UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.store_category_id             = c.store_category_id,
                        cmt.parent_store_category_id      = c.parent_store_category_id,
                        cmt.category_name                 = c.category_name,
                        cmt.order_number                  = c.order_number,
                        cmt.products_within_this_category = c.products_within_this_category"
                );

                $this->_doQuery(
                    "UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity cce
                        ON cmt.parent_store_category_id = cce.store_category_id
                    SET cmt.shop_parent_id = cce.entity_id"
                );

                //TODO: Doesn't work on single store currently as single-store parent category is named 'Default Category', not RootName
                //Single store just does:
                /*$this->_doQuery(
                    "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET shop_parent_id = " . $this->_rootCat . "
                    WHERE shop_parent_id = 0"
                );*/

                foreach ($rootCategories as $rootCat) {
                    $this->_doQuery(
                        "UPDATE $sinch_categories_mapping_temp cmt
                        JOIN $categories_temp c
                            ON cmt.shop_store_category_id = c.store_category_id
                        SET
                            cmt.shop_parent_id = :rootId,
                            cmt.shop_parent_store_category_id = :rootId,
                            cmt.parent_store_category_id = :rootId,
                            c.parent_store_category_id = :rootId
                        WHERE RootName = :rootName
                            AND cmt.shop_parent_id = 0",
                        [
                            ":rootId" => $rootCat['entity_id'],
                            ":rootName" => $rootCat['RootName']
                        ]
                    );
                }
            } else {
                $catalog_category_entity_backup = $this->getTableName('sinch_category_backup');
                if (!$this->tableHasData($catalog_category_entity_backup)) {
                    $catalog_category_entity_backup = $catalog_category_entity;
                }

                $this->_doQuery(
                    "INSERT IGNORE INTO $sinch_categories_mapping_temp
                        (
                            shop_entity_id,
                            shop_attribute_set_id,
                            shop_parent_id,
                            shop_store_category_id,
                            shop_parent_store_category_id
                        )
                    (SELECT
                        entity_id,
                        attribute_set_id,
                        parent_id,
                        store_category_id,
                        parent_store_category_id
                    FROM $catalog_category_entity_backup)"
                );

                $this->_doQuery(
                    "UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.store_category_id             = c.store_category_id,
                        cmt.parent_store_category_id      = c.parent_store_category_id,
                        cmt.category_name                 = c.category_name,
                        cmt.order_number                  = c.order_number,
                        cmt.products_within_this_category = c.products_within_this_category"
                );

                $this->_doQuery(
                    "UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity_backup cce
                        ON cmt.parent_store_category_id = cce.store_category_id
                    SET cmt.shop_parent_id = cce.entity_id"
                );

                //TODO: Doesn't work in single store (ditto), should be fine if multistore were the default
                //Single store just does:
                /*$this->_doQuery(
                    "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET shop_parent_id = " . $this->_rootCat . "
                    WHERE shop_parent_id = 0"
                );*/

                foreach ($rootCategories as $rootCat) {
                    $this->_doQuery(
                        "UPDATE $sinch_categories_mapping_temp cmt
                        JOIN $categories_temp c
                            ON cmt.shop_store_category_id = c.store_category_id
                        SET
                            cmt.shop_parent_id = :rootId,
                            cmt.shop_parent_store_category_id = :rootId,
                            cmt.parent_store_category_id = :rootId,
                            c.parent_store_category_id = :rootId
                        WHERE RootName = :rootName
                            AND cmt.shop_parent_id = 0",
                        [
                            ":rootId" => $rootCat['entity_id'],
                            ":rootName" => $rootCat['RootName']
                        ]
                    );
                }
            }
            // (end) backup Category ID in REWRITE mode
        } else {
            $this->_doQuery(
                "INSERT IGNORE INTO $sinch_categories_mapping_temp
                    (shop_entity_id, shop_attribute_set_id, shop_parent_id, shop_store_category_id, shop_parent_store_category_id)
                (SELECT entity_id, attribute_set_id, parent_id, store_category_id, parent_store_category_id
                FROM $catalog_category_entity)"
            );

            $this->_doQuery(
                "UPDATE $sinch_categories_mapping_temp cmt
                JOIN $categories_temp c
                    ON cmt.shop_store_category_id = c.store_category_id
                SET
                    cmt.store_category_id             = c.store_category_id,
                    cmt.parent_store_category_id      = c.parent_store_category_id,
                    cmt.category_name                 = c.category_name,
                    cmt.order_number                  = c.order_number,
                    cmt.products_within_this_category = c.products_within_this_category"
            );

            $this->_doQuery(
                "UPDATE $sinch_categories_mapping_temp cmt
                JOIN $catalog_category_entity cce
                    ON cmt.parent_store_category_id = cce.store_category_id
                SET cmt.shop_parent_id = cce.entity_id"
            );

            //TODO: Doesn't work on single store (ditto), should be fine if multistore default
            //Single store just does:
            /*$this->_doQuery(
                "
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $categories_temp c
                    ON cmt.shop_store_category_id = c.store_category_id
                SET shop_parent_id = " . $this->_rootCat . "
                WHERE shop_parent_id = 0"
            );*/

            foreach ($rootCategories as $rootCat) {
                $this->_doQuery(
                    "UPDATE $sinch_categories_mapping_temp cmt
                        JOIN $categories_temp c
                            ON cmt.shop_store_category_id = c.store_category_id
                        SET
                            cmt.shop_parent_id = :rootId,
                            cmt.shop_parent_store_category_id = :rootId,
                            cmt.parent_store_category_id = :rootId,
                            c.parent_store_category_id = :rootId
                        WHERE RootName = :rootName
                            AND cmt.shop_parent_id = 0",
                    [
                        ":rootId" => $rootCat['entity_id'],
                        ":rootName" => $rootCat['RootName']
                    ]
                );
            }
        }

        // added for mapping new sinch categories in merge && !UPDATE_CATEGORY_DATA mode
        if ((UPDATE_CATEGORY_DATA && $imType == "MERGE") || ($imType == "REWRITE")) {
            $where = '';
        } else {
            $where = 'WHERE cce.parent_id = 0 AND cce.store_category_id IS NOT NULL';
        }

        $this->_doQuery(
            "UPDATE $sinch_categories_mapping_temp cmt
            JOIN $catalog_category_entity cce
                ON cmt.shop_entity_id = cce.entity_id
            SET cce.parent_id = cmt.shop_parent_id
            $where"
        );
        $this->_log("Execute function mapSinchCategoriesMultistore");

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories_mapping");
        $this->_doQuery("RENAME TABLE $sinch_categories_mapping_temp TO $sinch_categories_mapping");
    }

    private function createMappingSinchTables()
    {
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $sinch_categories_mapping_temp = $this->getTableName('sinch_categories_mapping_temp');

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories_mapping_temp");
        $this->_doQuery(
            "CREATE TABLE $sinch_categories_mapping_temp
                (
                    shop_entity_id                INT(11) UNSIGNED NOT NULL,
                    shop_attribute_set_id         INT(11),
                    shop_parent_id                INT(11),
                    shop_store_category_id        INT(11),
                    shop_parent_store_category_id INT(11),
                    store_category_id             INT(11),
                    parent_store_category_id      INT(11),
                    category_name                 VARCHAR(255),
                    order_number                  INT(11),
                    products_within_this_category INT(11),

                    KEY shop_entity_id (shop_entity_id),
                    KEY shop_parent_id (shop_parent_id),
                    KEY store_category_id (store_category_id),
                    KEY parent_store_category_id (parent_store_category_id),
                    UNIQUE KEY(shop_entity_id)
                )"
        );

        $this->_doQuery(
            "CREATE TABLE IF NOT EXISTS $sinch_categories_mapping LIKE $sinch_categories_mapping_temp"
        );
    }

    //TODO: Remove pointless attribute ids passed as args
    private function addCategoryData(
        $_categoryDefault_attribute_set_id,
        $imType,
        $name_attrid,
        $attr_is_active,
        $attr_include_in_menu,
        $is_anchor_attrid,
        $image_attrid
    )
    {
        $categories_temp = $this->getTableName('categories_temp');
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $sinch_categories = $this->getTableName('sinch_categories');
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');

        $ignore = 'IGNORE';
        $onDuplicate = '';
        if (UPDATE_CATEGORY_DATA) {
            $ignore = '';
            $onDuplicate = "ON DUPLICATE KEY UPDATE
                        updated_at = NOW(),
                        store_category_id = c.store_category_id,
                        level = c.level,
                        children_count = c.children_count,
                        position = c.order_number,
                        parent_store_category_id = c.parent_store_category_id";
        }

        $this->_doQuery(
            "INSERT $ignore INTO $catalog_category_entity
                (
                    attribute_set_id,
                    created_at,
                    updated_at,
                    level,
                    children_count,
                    entity_id,
                    position,
                    parent_id,
                    store_category_id,
                    parent_store_category_id
                )
                (
                    SELECT
                    $_categoryDefault_attribute_set_id,
                    NOW(),
                    NOW(),
                    c.level,
                    c.children_count,
                    scm.shop_entity_id,
                    c.order_number,
                    scm.shop_parent_id,
                    c.store_category_id,
                    c.parent_store_category_id
                    FROM $categories_temp c
                    LEFT JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                ) $onDuplicate"
        );

        $this->mapSinchCategories($imType, $name_attrid, true);

        $categories = $this->_doQuery(
            "SELECT entity_id, parent_id FROM $catalog_category_entity ORDER BY parent_id"
        )->fetchAll();

        foreach ($categories as $category) {
            $parent_id = $category['parent_id'];
            $entity_id = $category['entity_id'];

            $path = $this->calculateCategoryPath($parent_id, $entity_id);

            $this->_doQuery(
                "UPDATE $catalog_category_entity SET path = :path WHERE entity_id = :entityId",
                [":path" => $path, ":entityId" => $entity_id]
            );
        }

        //TODO: Remove UPDATE_CATEGORY_DATA?
        if (UPDATE_CATEGORY_DATA) {
            $this->_doQuery(
                "INSERT INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value)
                    (
                        SELECT :includeInMenuAttr, 0, scm.shop_entity_id, c.include_in_menu
                        FROM $categories_temp c
                        JOIN $sinch_categories_mapping scm
                            ON c.store_category_id = scm.store_category_id
                    )
                    ON DUPLICATE KEY UPDATE
                        value = c.include_in_menu",
                [":includeInMenuAttr" => $attr_include_in_menu]
            );

            //Add values for both admin and primary store for these attributes
            foreach([0, 1] as $storeId) {
                $this->_doQuery(
                    "INSERT INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                        (
                            SELECT :nameAttr, :storeId, scm.shop_entity_id, c.category_name
                            FROM $categories_temp c
                            JOIN $sinch_categories_mapping scm
                                ON c.store_category_id = scm.store_category_id
                        )
                        ON DUPLICATE KEY UPDATE
                            value = c.category_name",
                    [":nameAttr" => $name_attrid, ":storeId" => $storeId]
                );

                $this->_doQuery(
                    "INSERT INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value)
                        (
                            SELECT :isActiveAttr, :storeId, scm.shop_entity_id, 1
                            FROM $categories_temp c
                            JOIN $sinch_categories_mapping scm
                                ON c.store_category_id = scm.store_category_id
                        )
                        ON DUPLICATE KEY UPDATE
                            value = 1",
                    [":isActiveAttr" => $attr_is_active, ":storeId" => $storeId]
                );

                $this->_doQuery(
                    "INSERT INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value)
                        (
                            SELECT :isAnchorAttr, :storeId, scm.shop_entity_id, c.is_anchor
                            FROM $categories_temp c
                            JOIN $sinch_categories_mapping scm
                                ON c.store_category_id = scm.store_category_id
                        )
                        ON DUPLICATE KEY UPDATE
                            value = c.is_anchor",
                    [":isAnchorAttr" => $is_anchor_attrid, ":storeId" => $storeId]
                );
            }

            //The following values are only inserted into admin scope
            $this->_doQuery(
                "INSERT INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                    (
                        SELECT :imageAttr, 0, scm.shop_entity_id, c.categories_image
                        FROM $categories_temp c
                        JOIN $sinch_categories_mapping scm
                            ON c.store_category_id = scm.store_category_id
                    )
                    ON DUPLICATE KEY UPDATE
                        value = c.categories_image",
                [":imageAttr" => $image_attrid]
            );

            $this->_doQuery(
                "INSERT INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                    (
                        SELECT :catMetaTitleAttr, 0, scm.shop_entity_id, c.MetaTitle
                        FROM $categories_temp c
                        JOIN $sinch_categories_mapping scm
                            ON c.store_category_id = scm.store_category_id
                    )
                    ON DUPLICATE KEY UPDATE
                        value = c.MetaTitle",
                [":catMetaTitleAttr" => $this->_categoryMetaTitleAttrId]
            );

            $this->_doQuery(
                "INSERT INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                    (
                        SELECT :catMetaDescriptionAttr, 0, scm.shop_entity_id, c.MetaDescription
                        FROM $categories_temp c
                        JOIN $sinch_categories_mapping scm
                            ON c.store_category_id = scm.store_category_id
                    )
                    ON DUPLICATE KEY UPDATE
                         value = c.MetaDescription",
                [":catMetaDescriptionAttr" => $this->_categoryMetadescriptionAttrId]
            );

            $this->_doQuery(
                "INSERT INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                    (
                        SELECT :catDescriptionAttr, 0, scm.shop_entity_id, c.Description
                        FROM $categories_temp c
                        JOIN $sinch_categories_mapping scm
                            ON c.store_category_id = scm.store_category_id
                    )
                    ON DUPLICATE KEY UPDATE
                        value = c.Description",
                [":catDescriptionAttr" => $this->_categoryDescriptionAttrId]
            );
        } else {
            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                (
                    SELECT :nameAttr, 0, scm.shop_entity_id, c.category_name
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":nameAttr" => $name_attrid]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value)
                (
                    SELECT :isActiveAttr, 0, scm.shop_entity_id, 1
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":isActiveAttr" => $attr_is_active]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value)
                (
                    SELECT :includeInMenuAttr, 0, scm.shop_entity_id, c.include_in_menu
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":includeInMenuAttr" => $attr_include_in_menu]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_int (attribute_id, store_id, entity_id, value)
                (
                    SELECT :isAnchorAttr, 0, scm.shop_entity_id, c.is_anchor
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":isAnchorAttr" => $is_anchor_attrid]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                (
                    SELECT :imageAttr, 0, scm.shop_entity_id, c.categories_image
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":imageAttr" => $image_attrid]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                (
                    SELECT :catMetaTitleAttr, 0, scm.shop_entity_id, c.MetaTitle
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":catMetaTitleAttr" => $this->_categoryMetaTitleAttrId]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                (
                    SELECT :catMetaDescriptionAttr, 0, scm.shop_entity_id, c.MetaDescription
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":catMetaDescriptionAttr" => $this->_categoryMetadescriptionAttrId]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_varchar (attribute_id, store_id, entity_id, value)
                (
                    SELECT :catDescriptionAttr, 0, scm.shop_entity_id, c.Description
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":catDescriptionAttr" => $this->_categoryDescriptionAttrId]
            );
        }

        if($imType == 'MERGE') {
            $this->deleteOldSinchCategoriesFromShopMerge();
        } else {
            $this->deleteOldSinchCategories();
        }

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories\n\n");
        $this->_doQuery("RENAME TABLE $categories_temp TO $sinch_categories");
    }

    //TODO: Remove pointless attribute ids passed as args
    private function mergeMultistoreCategories(
        $coincidence,
        $_categoryDefault_attribute_set_id,
        $imType,
        $name_attrid,
        $attr_display_mode,
        $attr_url_key,
        $attr_include_in_menu,
        $attr_is_active,
        $image_attrid,
        $is_anchor_attrid
    )
    {
        $categories_temp = $this->getTableName('categories_temp');
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $sinch_categories = $this->getTableName('sinch_categories');
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');

        $this->print("mergeMultistoreCategories RUN");

        $this->createNewDefaultCategories(
            $coincidence,
            $_categoryDefault_attribute_set_id,
            $name_attrid,
            $attr_display_mode,
            $attr_url_key,
            $attr_is_active,
            $attr_include_in_menu
        );

        $this->mapSinchCategories(
            $imType,
            $name_attrid
        );

        $this->addCategoryData(
            $_categoryDefault_attribute_set_id,
            $imType,
            $name_attrid,
            $attr_is_active,
            $attr_include_in_menu,
            $is_anchor_attrid,
            $image_attrid
        );

        $this->print("mergeMultistoreCategories DONE");
    }

    //TODO: Remove pointless attribute ids passed as args
    //TODO: Remove, almost identical duplicate of createDefaultCategories
    private function createNewDefaultCategories(
        $coincidence,
        $_categoryDefault_attribute_set_id,
        $name_attrid,
        $attr_display_mode,
        $attr_url_key,
        $attr_is_active,
        $attr_include_in_menu
    )
    {
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $catalog_category_entity_int = $this->getTableName('catalog_category_entity_int');

        $this->print("=== createNewDefaultCategories start...");

        $attributeId = $this->_eavAttribute->getIdByCode('catalog_category', 'name');
        $old_cats = $this->conn->fetchCol(
            "SELECT ccev.value
                    FROM $catalog_category_entity cce
                    JOIN $catalog_category_entity_varchar ccev
                        ON cce.entity_id = ccev.entity_id
                        AND ccev.store_id = 0
                        AND ccev.attribute_id = :nameAttr
                    WHERE parent_id = 1",
            [":nameAttr" => $attributeId]
        );

        $max_entity_id = (int)$this->conn->fetchOne(
            "SELECT MAX(entity_id) FROM $catalog_category_entity"
        );

        $i = $max_entity_id + 1;

        foreach ($coincidence as $key) {
            $this->print("Coincidence: key = [$key]");

            if (in_array($key, $old_cats)) {
                $this->print("CONTINUE: key = [$key]");
                continue;
            } else {
                $this->print("CREATE NEW CATEGORY: key = [$key]");
            }

            $this->_doQuery(
                "INSERT $catalog_category_entity
                        (entity_id, attribute_set_id, parent_id, created_at, updated_at,
                        path, position, level, children_count, store_category_id, parent_store_category_id)
                    VALUES
                        ($i, $_categoryDefault_attribute_set_id, 1, now(), now(), '1/$i', 1, 1, 1, NULL, NULL)"
            );

            $this->_doQuery(
                "INSERT $catalog_category_entity_varchar
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($name_attrid,       0, $i, '$key'),
                        ($name_attrid,       1, $i, '$key'),
                        ($attr_display_mode, 1, $i, '$key'),
                        ($attr_url_key,      0, $i, '$key')"
            );

            $this->_doQuery(
                "INSERT $catalog_category_entity_int
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($attr_is_active,       0, $i, 1),
                        ($attr_is_active,       1, $i, 1),
                        ($attr_include_in_menu, 0, $i, 1),
                        ($attr_include_in_menu, 1, $i, 1)"
            );
            $i++;
        }

        $this->print("Create New Default Categories -> DONE...");
    }

    private function deleteOldSinchCategoriesFromShopMerge()
    {
        $delete_cats = $this->getTableName('delete_cats');
        $this->_doQuery("DROP TABLE IF EXISTS $delete_cats");

        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $sinch_categories = $this->getTableName('sinch_categories');

        $this->_doQuery(
            "CREATE TABLE $delete_cats
                    SELECT entity_id
                    FROM $catalog_category_entity cce
                    WHERE cce.entity_id NOT IN
                    (
                        SELECT cce2.entity_id
                        FROM $catalog_category_entity cce2
                        JOIN $sinch_categories sc
                            ON cce2.store_category_id = sc.store_category_id
                    )
                    AND cce.store_category_id IS NOT NULL;"
        );

        $this->_doQuery(
            "DELETE cce FROM $catalog_category_entity cce JOIN $delete_cats dc USING(entity_id)"
        );
        $this->_doQuery("DROP TABLE IF EXISTS $delete_cats");
    }

    //TODO: Badly named
    private function _set_default_rootCategory()
    {
        $q = "UPDATE " . $this->getTableName('store_group') . " csg
            LEFT JOIN " . $this->getTableName('catalog_category_entity') . " cce
            ON csg.root_category_id = cce.entity_id
            SET csg.root_category_id=(SELECT entity_id FROM "
            . $this->getTableName('catalog_category_entity') . " WHERE parent_id = 1 LIMIT 1)
            WHERE csg.root_category_id > 0 AND cce.entity_id IS NULL";
        $this->_doQuery($q);
    }

    private function parseCategoryFeatures()
    {
        $parseFile = $this->varDir . FILE_CATEGORIES_FEATURES;
        if (filesize($parseFile) || $this->_ignore_category_features) {
            $this->_log("Start parse " . FILE_CATEGORIES_FEATURES);
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'categories_features_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName(
                    'categories_features_temp'
                ) . " (
                                category_feature_id int(11),
                                store_category_id int(11),
                                feature_name varchar(50),
                                display_order_number int(11),
                                KEY(store_category_id),
                                KEY(category_feature_id)
                          )
                        "
            );

            if (!$this->_ignore_category_features) {
                $this->_doQuery(
                    "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->getTableName(
                        'categories_features_temp'
                    ) . "
                              FIELDS TERMINATED BY '"
                    . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES "
                );
            }
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'sinch_categories_features'
                )
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName(
                    'categories_features_temp'
                ) . "
                          TO " . $this->getTableName(
                    'sinch_categories_features'
                )
            );

            $this->_log("Finish parse " . FILE_CATEGORIES_FEATURES);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function parseProductContracts()
    {
        $parseFile = $this->varDir . FILE_PRODUCT_CONTRACTS;
        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_PRODUCT_CONTRACTS);

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'product_contracts_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('product_contracts_temp')
                . "(
                          `store_product_id` int(11) DEFAULT NULL,
                          `contract_id` varchar(50) DEFAULT NULL,
                          KEY `store_product_id` (store_product_id)
                          )"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName(
                    'product_contracts_temp'
                ) . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char
                . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'sinch_product_contracts'
                )
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('product_contracts_temp')
                . "
                          TO " . $this->getTableName('sinch_product_contracts')
            );

            $this->_log("Finish parse " . FILE_PRODUCT_CONTRACTS);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function parseEANCodes()
    {
        $parseFile = $this->varDir . FILE_EANCODES;
        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_EANCODES);

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('ean_codes_temp')
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('ean_codes_temp') . "(
                           product_id int(11),
                           ean_code varchar(255),
                           KEY(product_id)
                          )"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName('ean_codes_temp')
                . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char
                . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'sinch_ean_codes'
                )
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('ean_codes_temp') . "
                          TO " . $this->getTableName('sinch_ean_codes')
            );

            $this->_log("Finish parse " . FILE_EANCODES);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function parseManufacturers()
    {
        $parseFile = $this->varDir . FILE_MANUFACTURERS;
        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_MANUFACTURERS);
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'manufacturers_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('manufacturers_temp') . "(
                                      sinch_manufacturer_id int(11),
                                      manufacturer_name varchar(255),
                                      manufacturers_image varchar(255),
                                      shop_option_id int(11),
                                      KEY(sinch_manufacturer_id),
                                      KEY(shop_option_id),
                                      KEY(manufacturer_name)
                          )"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName(
                    'manufacturers_temp'
                ) . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char
                . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );

            $q
                = "DELETE aov
                FROM " . $this->getTableName('eav_attribute_option') . " ao
                JOIN " . $this->getTableName('eav_attribute_option_value') . " aov
                    ON ao.option_id=aov.option_id left
                JOIN " . $this->getTableName('manufacturers_temp') . " mt
                    ON aov.value=mt.manufacturer_name
                WHERE
                    ao.attribute_id=" . $this->_getProductAttributeId(
                    'manufacturer'
                ) . " AND
                    mt.manufacturer_name is null";
            $this->_doQuery($q);

            $q
                = "DELETE ao
                FROM " . $this->getTableName('eav_attribute_option') . " ao
                LEFT JOIN " . $this->getTableName('eav_attribute_option_value')
                . " aov
                    ON ao.option_id=aov.option_id
                WHERE
                    attribute_id=" . $this->_getProductAttributeId(
                    'manufacturer'
                ) . " AND
                    aov.option_id is null";
            $this->_doQuery($q);

            $q
                = "SELECT
                    m.sinch_manufacturer_id,
                    m.manufacturer_name,
                    m.manufacturers_image
                FROM " . $this->getTableName('manufacturers_temp') . " m
                LEFT JOIN " . $this->getTableName('eav_attribute_option_value')
                . " aov
                    ON m.manufacturer_name=aov.value
                WHERE aov.value  IS NULL";
            $res = $this->_doQuery($q)->fetchAll();

            foreach ($res as $row) {
                $q0 = "INSERT INTO " . $this->getTableName(
                        'eav_attribute_option'
                    ) . "
                        (attribute_id)
                     VALUES(" . $this->_getProductAttributeId('manufacturer')
                    . ")";
                $this->_doQuery($q0);

                $q2 = "INSERT INTO " . $this->getTableName(
                        'eav_attribute_option_value'
                    ) . "(
                        option_id,
                        value
                     )(
                       SELECT
                        max(option_id) as option_id,
                        " . $this->conn->quote($row['manufacturer_name'])
                    . "
                       FROM " . $this->getTableName('eav_attribute_option') . "
                       WHERE attribute_id=" . $this->_getProductAttributeId(
                        'manufacturer'
                    ) . "
                     )
                    ";
                $this->_doQuery($q2);
            }

            $q = "UPDATE " . $this->getTableName('manufacturers_temp') . " mt
                JOIN  " . $this->getTableName('eav_attribute_option_value') . " aov
                    ON mt.manufacturer_name=aov.value
                JOIN " . $this->getTableName('eav_attribute_option') . " ao
                    ON ao.option_id=aov.option_id
                SET mt.shop_option_id=aov.option_id
                WHERE ao.attribute_id=" . $this->_getProductAttributeId(
                    'manufacturer'
                );
            $this->_doQuery($q);

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'sinch_manufacturers'
                )
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('manufacturers_temp') . "
                          TO " . $this->getTableName('sinch_manufacturers')
            );
            $this->_log("Finish parse " . FILE_MANUFACTURERS);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function _getProductAttributeId($attributeCode)
    {
        return $this->_getAttributeId($attributeCode, 'catalog_product');
    }

    private function parseRelatedProducts()
    {
        $parseFile = $this->varDir . FILE_RELATED_PRODUCTS;
        if (filesize($parseFile) || $this->_ignore_product_related) {
            $this->_log("Start parse " . FILE_RELATED_PRODUCTS);
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'related_products_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('related_products_temp') . "(
                         sinch_product_id int(11),
                         related_sinch_product_id int(11),
                         store_related_product_id int(11) default null,
                         entity_id int(11),
                         related_entity_id int(11),
                         KEY(sinch_product_id),
                         KEY(related_sinch_product_id)
                )DEFAULT CHARSET=utf8"
            );
            if (!$this->_ignore_product_related) {
                $this->_doQuery(
                    "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->getTableName('related_products_temp') . "
                              FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES
                              (sinch_product_id, related_sinch_product_id)"
                );
            }
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('sinch_related_products')
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('related_products_temp')
                . " TO " . $this->getTableName('sinch_related_products')
            );

            $this->_log("Finish parse " . FILE_RELATED_PRODUCTS);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function parseProductCategories()
    {
        $parseFile = $this->varDir . FILE_PRODUCT_CATEGORIES;
        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_PRODUCT_CATEGORIES);

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'product_categories_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName(
                    'product_categories_temp'
                ) . "(
                          store_product_id int(11),
                          store_category_id int(11),
                          key(store_product_id),
                          key(store_category_id)
                          )"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName(
                    'product_categories_temp'
                ) . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char
                . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'sinch_product_categories'
                )
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName(
                    'product_categories_temp'
                ) . "
                          TO " . $this->getTableName(
                    'sinch_product_categories'
                )
            );

            $this->_log("Finish parse " . FILE_PRODUCT_CATEGORIES);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function parseProducts($coincidence)
    {
        $this->print("--Parse Products 1");

        $replace_merge_product = $this->_dataConf['replace_product'];

        $parseFile = $this->varDir . FILE_PRODUCTS;
        //$parseFile = $this->varDir . FILE_PRODUCTS_TEST;
        if (filesize($parseFile)) {
            $this->_log("Start parse " . FILE_PRODUCTS);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->getTableName('products_temp'));
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('products_temp') . "(
                         sinch_product_id int(11),
                         product_sku varchar(255),
                         product_name varchar(255),
                         sinch_manufacturer_id int(11),
                         main_image_url varchar(255),
                         thumb_image_url varchar(255),
                         specifications text,
                         description text,
                         search_cache text,
                         description_type varchar(50),
                         medium_image_url varchar(255),
                         Title varchar(255),
                         Weight decimal(15,4),
                         Family varchar(255),
                         Reviews varchar(255),
                         pdf_url varchar(255),
                         product_short_description varchar(255),
                         unspsc int(11),
                         products_date_added datetime default NULL,
                         products_last_modified datetime default NULL,
                         availability_id_in_stock int(11) default '1',
                         availability_id_out_of_stock int(11) default '2',
                         products_locate varchar(30) default NULL,
                         products_ordered int(11) NOT NULL default '0',
                         products_url varchar(255) default NULL,
                         products_viewed int(5) default '0',
                         products_seo_url varchar(100) NOT NULL,
                         manufacturer_name varchar(255) default NULL,
                         store_category_id int(11),
                         KEY pt_store_category_product_id (`store_category_id`),
                         KEY pt_product_sku (`product_sku`),
                         KEY pt_sinch_product_id (`sinch_product_id`),
                         KEY pt_sinch_manufacturer_id (`sinch_manufacturer_id`),
                         KEY pt_manufacturer_name (`manufacturer_name`)
                      )DEFAULT CHARSET=utf8
                    "
            );
            $this->print("--Parse Products 2");

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName('products_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );


            $this->_doQuery(
                "UPDATE " . $this->getTableName('products_temp') . "
                      SET product_name = Title WHERE Title != ''"
            );
            $this->_doQuery(
                "UPDATE " . $this->getTableName('products_temp') . " pt
                JOIN " . $this->getTableName('sinch_product_categories') . " spc
                SET pt.store_category_id=spc.store_category_id
                WHERE pt.sinch_product_id=spc.store_product_id"
            );
            $this->_doQuery(
                "UPDATE " . $this->getTableName('products_temp') . "
                      SET main_image_url = medium_image_url WHERE main_image_url = ''"
            );

            $this->unspscImport->parse();

            $this->print("--Parse Products 3");

            $this->_doQuery(
                "UPDATE " . $this->getTableName('products_temp') . "
                          SET products_date_added=now(), products_last_modified=now()"
            );

            $this->print("--Parse Products 4");

            $this->_doQuery(
                "UPDATE " . $this->getTableName('products_temp') . " p
                          JOIN " . $this->getTableName('sinch_manufacturers') . " m
                            ON p.sinch_manufacturer_id=m.sinch_manufacturer_id
                          SET p.manufacturer_name=m.manufacturer_name"
            );

            $this->print("--Parse Products 5");

            if ($this->current_import_status_statistic_id) {
                $res = $this->_doQuery(
                    "SELECT COUNT(*) AS cnt
                                     FROM " . $this->getTableName(
                        'products_temp'
                    )
                )->fetch();
                $this->_doQuery(
                    "UPDATE " . $this->import_status_statistic_table . "
                              SET number_of_products=" . $res['cnt'] . "
                              WHERE id="
                    . $this->current_import_status_statistic_id
                );
            }

            if ($replace_merge_product == "REWRITE") {
                $catalog_product_entity = $this->getTableName('catalog_product_entity');
                //Allow retrying, as this is particularly likely to deadlock if the site is being used
                $this->retriableQuery("DELETE FROM $catalog_product_entity WHERE type_id = 'simple' AND sinch_product_id IS NOT NULL");
            }

            $this->print("--Parse Products 6");

            $this->addProductsWebsite();
            $this->mapSinchProducts($replace_merge_product);

            $this->print("--Parse Products 7");

            if (count($coincidence) == 1) {
                $this->replaceMagentoProducts();
            } else {
                switch ($this->imType) {
                    case "REWRITE":
                        $this->replaceMagentoProductsMultistore();
                        break;
                    case "MERGE":
                        $this->replaceMagentoProductsMultistoreMERGE();
                        break;
                }
            }

            $this->print("--Parse Products 8");

            $this->mapSinchProducts($replace_merge_product, true);
            $this->addManufacturer_attribute();
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('sinch_products')
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('products_temp') . "
                          TO " . $this->getTableName('sinch_products')
            );
            $this->_log("Finish parse " . FILE_PRODUCTS);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function retriableQuery($query): Zend_Db_Statement_Interface
    {
        while (true) {
            try {
                return $this->_doQuery($query);
            } catch (DeadlockException $_e) {
                $this->print("Sleeping as the previous attempt deadlocked");
                sleep(10);
            }
        }
    }

    private function addProductsWebsite()
    {
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->getTableName('products_website_temp'));

        $this->_doQuery(
            "CREATE TABLE `" . $this->getTableName('products_website_temp') . "` (
                    `id` int(10) unsigned NOT NULL auto_increment,
                    sinch_product_id int(11),
                    `website` int(11) default NULL,
                    `website_id` int(11) default NULL,
                    PRIMARY KEY  (`id`),
                    KEY sinch_product_id (`sinch_product_id`)
                )"
        );
        $result = $this->_doQuery(
            "SELECT
                                    website_id,
                                    store_id as website
                                FROM " . $this->getTableName('store') . "
                                WHERE code!='admin'
                              "
        )->fetchAll(); //  where code!='admin' was adder for editing Featured products;

        foreach ($result as $store) {
            $sql = "INSERT INTO " . $this->getTableName('products_website_temp') . " (
                        sinch_product_id,
                        website,
                        website_id
                    )(
                      SELECT
                        distinct
                        sinch_product_id,
                        {$store['website']},
                        {$store['website_id']}
                      FROM " . $this->getTableName('products_temp') . "
                    )";
            $this->_doQuery($sql);
        }
    }

    private function mapSinchProducts($mode = 'MERGE', $mapping_again = false)
    {
        $this->_doQuery(
            "DROP TABLE IF EXISTS " . $this->getTableName('sinch_products_mapping_temp')
        );
        $this->_doQuery(
            "CREATE TABLE " . $this->getTableName('sinch_products_mapping_temp') . " (
                      entity_id int(11) unsigned NOT NULL,
                      manufacturer_option_id int(11),
                      manufacturer_name varchar(255),
                      shop_sinch_product_id int(11),
                      sku varchar(64) default NULL,
                      sinch_product_id int(11),
                      product_sku varchar(255),
                      sinch_manufacturer_id int(11),
                      sinch_manufacturer_name varchar(255),
                      KEY entity_id (entity_id),
                      KEY manufacturer_option_id (manufacturer_option_id),
                      KEY manufacturer_name (manufacturer_name),
                      KEY sinch_manufacturer_id (sinch_manufacturer_id),
                      KEY sinch_manufacturer_name (sinch_manufacturer_name),
                      KEY sinch_product_id (sinch_product_id),
                      KEY sku (sku),
                      KEY product_sku (product_sku),
                      UNIQUE KEY(entity_id)
                          )
                          "
        );

        $productEntityTable = $this->getTableName('catalog_product_entity');

        // backup Product ID in REWRITE mode
        $productsBackupTable = $this->getTableName('sinch_product_backup');
        if ($mode == 'REWRITE' && !$mapping_again
            && $this->tableHasData(
                $productsBackupTable
            )
        ) {
            $productEntityTable = $productsBackupTable;
        }
        // (end) backup Product ID in REWRITE mode

        $this->_doQuery(
            "INSERT ignore INTO " . $this->getTableName('sinch_products_mapping_temp') . " (
                entity_id,
                sku,
                shop_sinch_product_id
            )(SELECT
                entity_id,
                sku,
                sinch_product_id
              FROM " . $productEntityTable . "
             )"
        );

        $this->addManufacturers(1);

        $q = "UPDATE " . $this->getTableName('sinch_products_mapping_temp') . " pmt
            JOIN " . $this->getTableName('catalog_product_index_eav') . " cpie
                ON pmt.entity_id=cpie.entity_id
            JOIN " . $this->getTableName('eav_attribute_option_value') . " aov
                ON cpie.value=aov.option_id
            SET
                manufacturer_option_id=cpie.value,
                manufacturer_name=aov.value
            WHERE cpie.attribute_id=" . $this->_getProductAttributeId(
                'manufacturer'
            );
        $this->_doQuery($q);

        $q = "UPDATE " . $this->getTableName('sinch_products_mapping_temp') . " pmt
            JOIN " . $this->getTableName('products_temp') . " p
                ON pmt.sku=p.product_sku
            SET
                pmt.sinch_product_id=p.sinch_product_id,
                pmt.product_sku=p.product_sku,
                pmt.sinch_manufacturer_id=p.sinch_manufacturer_id,
                pmt.sinch_manufacturer_name=p.manufacturer_name";

        $this->_doQuery($q);

        $q = "UPDATE " . $this->getTableName('catalog_product_entity') . " cpe
            JOIN " . $this->getTableName('sinch_products_mapping_temp') . " pmt
                ON cpe.entity_id=pmt.entity_id
            SET cpe.sinch_product_id=pmt.sinch_product_id
            WHERE
                cpe.sinch_product_id IS NULL
                AND pmt.sinch_product_id IS NOT NULL";
        $this->_doQuery($q);

        $this->_doQuery(
            "DROP TABLE IF EXISTS " . $this->getTableName('sinch_products_mapping')
        );
        $this->_doQuery(
            "RENAME TABLE " . $this->getTableName('sinch_products_mapping_temp') . " TO " . $this->getTableName('sinch_products_mapping')
        );
    }

    private function addManufacturers($delete_eav = null)
    {
        // this cleanup is not needed due to foreign keys
        if (!$delete_eav) {
            $this->_doQuery(
                "DELETE FROM " . $this->getTableName(
                    'catalog_product_index_eav'
                ) . "
                                    WHERE attribute_id = "
                . $this->_getProductAttributeId(
                    'manufacturer'
                )//." AND store_id = ".$websiteId
            );
        }
        $this->addManufacturer_attribute();

        //TODO: Don't touch index tables
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_index_eav') . " (
                entity_id,
                attribute_id,
                store_id,
                value
            )(
              SELECT
                a.entity_id,
                " . $this->_getProductAttributeId('manufacturer') . ",
                w.website,
                mn.shop_option_id
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " w
                ON a.sinch_product_id=w.sinch_product_id
              INNER JOIN " . $this->getTableName('sinch_manufacturers') . " mn
                ON b.sinch_manufacturer_id=mn.sinch_manufacturer_id
              WHERE mn.shop_option_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = mn.shop_option_id"
        );

        //TODO: Don't touch index tables
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_index_eav') . " (
                entity_id,
                attribute_id,
                store_id,
                value
            )(
              SELECT
                a.entity_id,
                " . $this->_getProductAttributeId('manufacturer') . ",
                0,
                mn.shop_option_id
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " w
                ON a.sinch_product_id=w.sinch_product_id
              INNER JOIN " . $this->getTableName('sinch_manufacturers') . " mn
                ON b.sinch_manufacturer_id=mn.sinch_manufacturer_id
              WHERE mn.shop_option_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = mn.shop_option_id"
        );
    }

    private function addManufacturer_attribute()
    {
        $this->_doQuery(
            "
                                INSERT INTO " . $this->getTableName(
                'catalog_product_entity_int'
            ) . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId(
                'manufacturer'
            ) . ",
                                    0,
                                    a.entity_id,
                                    pm.manufacturer_option_id
                                  FROM " . $this->getTableName(
                'catalog_product_entity'
            ) . " a
                                  INNER JOIN " . $this->getTableName(
                'sinch_products_mapping'
            ) . " pm
                                    ON a.entity_id = pm.entity_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = pm.manufacturer_option_id
                              "
        );
    }

    private function replaceMagentoProducts()
    {
        $this->_doQuery(
            "DELETE cpe
                FROM " . $this->getTableName('catalog_product_entity') . " cpe
                JOIN " . $this->getTableName('sinch_products_mapping') . " pm
                    ON cpe.entity_id = pm.entity_id
                WHERE pm.shop_sinch_product_id IS NOT NULL
                    AND pm.sinch_product_id IS NULL"
        );

        //Inserting new products and updating old others.
        $this->_getProductDefaulAttributeSetId();
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity') . " (
                entity_id,
                attribute_set_id,
                type_id,
                sku,
                updated_at,
                has_options,
                sinch_product_id
            )(SELECT
                 pm.entity_id,
                 $this->defaultAttributeSetId,
                 'simple',
                 a.product_sku,
                 NOW(),
                 0,
                 a.sinch_product_id
              FROM " . $this->getTableName('products_temp') . " a
              LEFT JOIN " . $this->getTableName('sinch_products_mapping') . " pm
                 ON a.sinch_product_id = pm.sinch_product_id
              WHERE pm.entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = a.product_sku,
                sinch_product_id = a.sinch_product_id"
        );

        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity') . " (
                entity_id,
                attribute_set_id,
                type_id,
                sku,
                updated_at,
                has_options,
                sinch_product_id
            )(SELECT
                 pm.entity_id,
                 $this->defaultAttributeSetId,
                 'simple',
                 a.product_sku,
                 NOW(),
                 0,
                 a.sinch_product_id
              FROM " . $this->getTableName('products_temp') . " a
              LEFT JOIN " . $this->getTableName('sinch_products_mapping') . " pm
                 ON a.sinch_product_id=pm.sinch_product_id
              WHERE pm.entity_id IS NULL
            )
            ON DUPLICATE KEY UPDATE
                sku= a.product_sku,
                sinch_product_id=a.sinch_product_id"
        );

        //Set enabled
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_int') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
                SELECT
                " . $this->_getProductAttributeId('status') . ",
                w.website,
                a.entity_id,
                1
                FROM " . $this->getTableName('catalog_product_entity') . " a
                INNER JOIN " . $this->getTableName('products_website_temp') . " w
                    ON a.sinch_product_id = w.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value=1"
        );

        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_int') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(SELECT
                " . $this->_getProductAttributeId('status') . ",
                0,
                a.entity_id,
                1
              FROM " . $this->getTableName('catalog_product_entity') . " a
            )
            ON DUPLICATE KEY UPDATE
                value=1"
        );

        //Unifying products with categories.

        //TODO: This seems to change category mapping to the root category when the actual category doesn't exist
        $this->_doQuery(
            "UPDATE IGNORE " . $this->getTableName('catalog_category_product')
            . " ccp
                                LEFT JOIN " . $this->getTableName(
                'catalog_category_entity'
            ) . " cce
                                    ON ccp.category_id=cce.entity_id
                                SET ccp.category_id=" . $this->_rootCat . "
                                WHERE cce.entity_id IS NULL"
        );

        $this->_doQuery(
            "DROP TABLE IF EXISTS " . $this->getTableName('catalog_category_product') . "_for_delete_temp"
        );
        // TEMPORARY
        $this->_doQuery(
            "CREATE TABLE `" . $this->getTableName('catalog_category_product') . "_for_delete_temp` (
                `category_id` int(10) unsigned NOT NULL default '0',
                `product_id` int(10) unsigned NOT NULL default '0',
                `store_product_id` int(10) NOT NULL default '0',
                `store_category_id` int(10) NOT NULL default '0',
                `new_category_id` int(10) NOT NULL default '0',
                UNIQUE KEY `UNQ_CATEGORY_PRODUCT` (`category_id`,`product_id`),
                KEY `CATALOG_CATEGORY_PRODUCT_CATEGORY` (`category_id`),
                KEY `CATALOG_CATEGORY_PRODUCT_PRODUCT` (`product_id`),
                KEY `CATALOG_NEW_CATEGORY_PRODUCT_CATEGORY` (`new_category_id`)
            )"
        );

        //Make existing links for sinch products eligible for deletion
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_category_product') . "_for_delete_temp (
                category_id,
                product_id,
                store_product_id
            )(SELECT
                ccp.category_id,
                ccp.product_id,
                cpe.sinch_product_id
              FROM " . $this->getTableName('catalog_category_product') . " ccp
              INNER JOIN " . $this->getTableName('catalog_product_entity') . " cpe
                ON ccp.product_id=cpe.entity_id
              WHERE cpe.sinch_product_id IS NOT NULL
            )"
        );

        $this->_doQuery(
            "UPDATE " . $this->getTableName('catalog_category_product') . "_for_delete_temp ccpfd
                        INNER JOIN " . $this->getTableName('products_temp') . " p
                            ON ccpfd.store_product_id=p.sinch_product_id
                        SET ccpfd.store_category_id=p.store_category_id
                        WHERE ccpfd.store_product_id!=0"
        );

        $this->_doQuery(
            "UPDATE " . $this->getTableName('catalog_category_product') . "_for_delete_temp ccpfd
                                INNER JOIN " . $this->getTableName('sinch_categories_mapping') . " scm
                                    ON ccpfd.store_category_id=scm.store_category_id
                                SET ccpfd.new_category_id=scm.shop_entity_id
                                WHERE ccpfd.store_category_id!=0"
        );

        $this->_doQuery(
            "DELETE FROM " . $this->getTableName('catalog_category_product') . "_for_delete_temp
                                WHERE category_id=new_category_id"
        );

        //Delete primary links that no longer exist in the new feed
        $this->_doQuery(
            "DELETE ccp FROM " . $this->getTableName('catalog_category_product') . " ccp
                                INNER JOIN " . $this->getTableName('catalog_category_product') . "_for_delete_temp ccpfd
                                    ON ccp.product_id=ccpfd.product_id
                                    AND ccp.category_id=ccpfd.category_id"
        );

        //add multi categories;
        $catalog_category_product = $this->getTableName("catalog_category_product");
        $sinch_product_categories = $this->getTableName("sinch_product_categories");
        $catalog_product_entity = $this->getTableName("catalog_product_entity");
        $sinch_categories_mapping = $this->getTableName("sinch_categories_mapping");

        $this->_doQuery("INSERT INTO $catalog_category_product (category_id, product_id) (
            SELECT scm.shop_entity_id, cpe.entity_id
                FROM $sinch_product_categories spc
            INNER JOIN $catalog_product_entity cpe
                ON spc.store_product_id = cpe.sinch_product_id
            INNER JOIN $sinch_categories_mapping scm
                ON spc.store_category_id = scm.store_category_id
        ) ON DUPLICATE KEY UPDATE product_id = cpe.entity_id, category_id = scm.shop_entity_id");

        //Set product name for specific web sites
        $this->_doQuery(
            "DELETE cpev
                        FROM " . $this->getTableName('catalog_product_entity_varchar') . " cpev
                        LEFT JOIN " . $this->getTableName('catalog_product_entity') . " cpe
                        ON cpev.entity_id=cpe.entity_id
                        WHERE cpe.entity_id IS NULL"
        );
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(SELECT
                " . $this->_getProductAttributeId('name') . ",
                w.website,
                a.entity_id,
                b.product_name
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " w
                ON a.sinch_product_id = w.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.product_name"
        );

        // product name for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('name') . ",
                0,
                a.entity_id,
                b.product_name
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.product_name"
        );

        $this->dropHTMLentities(
            $this->_getProductAttributeId('name')
        );
        $this->addDescriptions();
        $this->cleanProductDistributors();
        if (!$this->_ignore_product_contracts) {
            $this->cleanProductContracts();
        }
        $this->addReviews();
        $this->addWeight();
        $this->addPdfUrl();
        $this->addShortDescriptions();
        $this->stockPriceImport->applyDistributors();
        if (!$this->_ignore_product_contracts) {
            $this->addProductContracts();
        }
        $this->addMetaTitle();
        $this->addMetaDescriptions();
        $this->addEAN();
        $this->addSpecification();
        $this->addManufacturers();

        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_int') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('visibility') . ",
                w.website,
                a.entity_id,
                4
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_website_temp') . " w
              ON a.sinch_product_id=w.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
            value = 4"
        );

        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_int') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('visibility') . ",
                0,
                a.entity_id,
                4
              FROM " . $this->getTableName('catalog_product_entity') . " a
            )
            ON DUPLICATE KEY UPDATE
                value = 4"
        );

        $this->_doQuery(
            "DELETE cpw
                        FROM " . $this->getTableName('catalog_product_website') . " cpw
                        LEFT JOIN " . $this->getTableName('catalog_product_entity') . " cpe
                            ON cpw.product_id=cpe.entity_id
                        WHERE cpe.entity_id IS NULL"
        );

        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_website') . " (
                product_id,
                website_id
            )(
              SELECT a.entity_id, w.website_id
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_website_temp') . " w
                  ON a.sinch_product_id=w.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                product_id=a.entity_id,
                website_id=w.website_id"
        );

        //Adding tax class "Taxable Goods"
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_int') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('tax_class_id') . ",
                w.website,
                a.entity_id,
                2
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_website_temp') . " w
              ON a.sinch_product_id=w.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 2"
        );
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_int') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('tax_class_id') . ",
                0,
                a.entity_id,
                2
              FROM " . $this->getTableName('catalog_product_entity') . " a
            )
            ON DUPLICATE KEY UPDATE
                value = 2"
        );

        // Load url Image
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('image') . ",
                w.store_id,
                a.entity_id,
                b.main_image_url
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('store') . " w
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.main_image_url"
        );
        // image for specific web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('image') . ",
                0,
                a.entity_id,
                b.main_image_url
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.main_image_url"
        );
        // small_image for specific web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('small_image') . ",
                w.store_id,
                a.entity_id,
                b.medium_image_url
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('store') . " w
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.medium_image_url"
        );
        // small_image for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('small_image') . ",
                0,
                a.entity_id,
                b.medium_image_url
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('store') . " w
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.medium_image_url"
        );
        // thumbnail for specific web site
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('thumbnail') . ",
                w.store_id,
                a.entity_id,
                b.thumb_image_url
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('store') . " w
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.thumb_image_url"
        );
        // thumbnail for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('thumbnail') . ",
                0,
                a.entity_id,
                b.thumb_image_url
              FROM " . $this->getTableName('catalog_product_entity') . " a
              INNER JOIN " . $this->getTableName('store') . " w
              INNER JOIN " . $this->getTableName('products_temp') . " b
                ON a.sinch_product_id = b.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.thumb_image_url"
        );

        $this->addRelatedProducts();
    }

    private function _getProductDefaulAttributeSetId()
    {
        if (!$this->defaultAttributeSetId) {
            $sql
                = "
                SELECT entity_type_id, default_attribute_set_id
                FROM " . $this->getTableName('eav_entity_type') . "
                WHERE entity_type_code = 'catalog_product'
                LIMIT 1
                ";
            $result = $this->_doQuery($sql)->fetch();
            $this->defaultAttributeSetId = $result['default_attribute_set_id'];
        }

        return $this->defaultAttributeSetId;
    }

    private function dropHTMLentities($attribute_id)
    {
        // product name for all web sites
        $results = $this->_doQuery(
            "
                                SELECT value, entity_id
                                FROM " . $this->getTableName(
                'catalog_product_entity_varchar'
            ) . "
                                WHERE attribute_id=" . $attribute_id
        )->fetchAll();

        foreach ($results as $result) {
            $value = $this->valid_char($result['value']);
            if ($value != '' and $value != $result['value']) {
                $this->_doQuery(
                    "UPDATE " . $this->getTableName(
                        'catalog_product_entity_varchar'
                    ) . "
                              SET value=" . $this->conn->quote($value) . "
                              WHERE entity_id=" . $result['entity_id'] . "
                              AND attribute_id=" . $attribute_id
                );
            }
        }
    }

    private function valid_char($string)
    {
        $string = preg_replace('/&#8482;/', ' ', $string);
        $string = preg_replace('/&reg;/', ' ', $string);
        $string = preg_replace('/&asymp;/', ' ', $string);
        $string = preg_replace('/&quot;/', ' ', $string);
        $string = preg_replace('/&prime;/', ' ', $string);
        $string = preg_replace('/&deg;/', ' ', $string);
        $string = preg_replace('/&plusmn;/', ' ', $string);
        $string = preg_replace('/&micro;/', ' ', $string);
        $string = preg_replace('/&sup2;/', ' ', $string);
        $string = preg_replace('/&sup3;/', ' ', $string);

        return $string;
    }

    private function addDescriptions()
    {
        // product description for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_text') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('description') . ",
                pwt.website,
                cpe.entity_id,
                pt.description
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.description"
        );

        // product description for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_text') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('description') . ",
                0,
                cpe.entity_id,
                pt.description
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.description"
        );
    }

    private function cleanProductDistributors()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->_doQuery(
                "UPDATE " . $this->getTableName('catalog_product_entity_varchar') . "
                    SET value = ''
                    WHERE attribute_id = " . $this->_getProductAttributeId('supplier_' . $i)
            );
        }
    }

    private function cleanProductContracts()
    {
        $this->_doQuery(
            "UPDATE " . $this->getTableName('catalog_product_entity_varchar') . "
                    SET value = ''
                    WHERE attribute_id = " . $this->_getProductAttributeId('contract_id')
        );
    }

    private function addReviews()
    {
        // product reviews for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_text') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('reviews') . ",
                pwt.website,
                cpe.entity_id,
                pt.Reviews
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.Reviews"
        );

        // product Reviews for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_text') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('reviews') . ",
                0,
                cpe.entity_id,
                pt.Reviews
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.Reviews"
        );
    }

    private function addWeight()
    {
        // product weight for specific web site
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_decimal') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('weight') . ",
                pwt.website,
                cpe.entity_id,
                pt.Weight
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.Weight"
        );
        // product weight for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_decimal') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('weight') . ",
                0,
                cpe.entity_id,
                pt.Weight
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.Weight"
        );
    }

    private function addPdfUrl()
    {
        // product PDF Url for all web sites
        $this->_doQuery(
            "UPDATE " . $this->getTableName('products_temp') . "
                    SET pdf_url = CONCAT(
                        '<a href=\"#\" onclick=\"popWin(',
                        \"'\",
                        pdf_url,
                        \"'\",
                        \", 'pdf', 'width=500,height=800,left=50,top=50, location=no,status=yes,scrollbars=yes,resizable=yes'); return false;\",
                        '\"',
                        '>',
                        pdf_url,
                        '</a>'
                    )
                    WHERE pdf_url != ''"
        );

        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('pdf_url') . ",
                pwt.website,
                cpe.entity_id,
                pt.pdf_url
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.pdf_url"
        );
        // product  PDF url for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('pdf_url') . ",
                0,
                cpe.entity_id,
                pt.pdf_url
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.pdf_url"
        );
    }

    private function addShortDescriptions()
    {
        // product short description for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('short_description') . ",
                pwt.website,
                cpe.entity_id,
                pt.product_short_description
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_short_description"
        );
        // product short description for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('short_description') . ",
                0,
                cpe.entity_id,
                pt.product_short_description
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_short_description"
        );
    }

    private function addProductContracts()
    {
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->getTableName('sinch_product_contracts_temporary'));
        $this->_doQuery(
            "CREATE TABLE IF NOT EXISTS " . $this->getTableName('sinch_product_contracts_temporary') . "(
              `store_product_id` int(11) DEFAULT NULL,
              `contract_id_str` varchar(255) DEFAULT NULL,
              KEY `store_product_id` (store_product_id)
            )"
        );
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('sinch_product_contracts_temporary') . "
                    SELECT store_product_id, group_concat(contract_id)
                    FROM " . $this->getTableName('sinch_product_contracts') . "
                    GROUP BY store_product_id"
        );

        // add contract to product for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('contract_id') . ",
                pwt.website,
                cpe.entity_id,
                spct.contract_id_str
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('sinch_product_contracts_temporary') . " spct
                ON cpe.sinch_product_id = spct.store_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = spct.contract_id_str"
        );

        // add contract to product for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('contract_id') . ",
                0,
                cpe.entity_id,
                spct.contract_id_str
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('sinch_product_contracts_temporary') . " spct
                ON cpe.sinch_product_id = spct.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = spct.contract_id_str"
        );
    }

    private function addMetaTitle()
    {
        $configMetaTitle = $this->scopeConfig->getValue(
            'sinchimport/general/meta_title',
            ScopeInterface::SCOPE_STORE);

        if ($configMetaTitle == 1) {
            $this->_doQuery(
                "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                    attribute_id,
                    store_id,
                    entity_id,
                    value
                )(
                  SELECT
                    " . $this->_getProductAttributeId('meta_title') . ",
                    pwt.website,
                    cpe.entity_id,
                    pt.Title
                  FROM " . $this->getTableName('catalog_product_entity') . " cpe
                  INNER JOIN " . $this->getTableName('products_temp') . " pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
                  INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                    ON cpe.sinch_product_id = pwt.sinch_product_id
                )
                ON DUPLICATE KEY UPDATE
                    value = pt.Title"
            );

            $this->_doQuery(
                "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                    attribute_id,
                    store_id,
                    entity_id,
                    value
                )(
                  SELECT
                    " . $this->_getProductAttributeId('meta_title') . ",
                    0,
                    cpe.entity_id,
                    pt.Title
                  FROM " . $this->getTableName('catalog_product_entity') . " cpe
                  INNER JOIN " . $this->getTableName('products_temp') . " pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
                )
                ON DUPLICATE KEY UPDATE
                    value = pt.Title"
            );
        } else {
            $this->print("-- Ignore the meta title for product configuration.");
            $this->_logImportInfo("-- Ignore the meta title for product configuration.");
        }
    }

    /**
     * @param string $logString
     * @param bool $isError
     */
    protected function _logImportInfo($logString = '', $isError = false)
    {
        if ($logString) {
            if ($isError) {
                $logString = "[ERROR] " . $logString;
            }
            $this->_sinchLogger->info($logString);
        }
    }

    private function addMetaDescriptions()
    {
        // product meta description for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('meta_description') . ",
                pwt.website,
                cpe.entity_id,
                pt.product_short_description
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_short_description"
        );
        // product meta description for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('meta_description') . ",
                0,
                cpe.entity_id,
                pt.product_short_description
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_short_description"
        );
    }

    private function addEAN()
    {
        //gather EAN codes for each product
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->getTableName('EANs_temp'));
        $this->_doQuery(
            "CREATE TEMPORARY TABLE " . $this->getTableName('EANs_temp') . " (
                sinch_product_id int(11),
                EANs text,
                KEY `sinch_product_id` (`sinch_product_id`)
            )"
        );
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('EANs_temp') . " (
                sinch_product_id,
                EANs
            )(SELECT
                sec.product_id,
                GROUP_CONCAT(DISTINCT ean_code ORDER BY ean_code DESC SEPARATOR ', ') AS eans
                FROM " . $this->getTableName('sinch_ean_codes') . " sec
                GROUP BY sec.product_id
            )"
        );

        // product EANs for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('ean') . ",
                pwt.website,
                cpe.entity_id,
                e.EANs
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('EANs_temp') . " e
                ON cpe.sinch_product_id = e.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = e.EANs"
        );

        // product EANs for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_varchar') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('ean') . ",
                0,
                cpe.entity_id,
                e.EANs
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('EANs_temp') . " e
                ON cpe.sinch_product_id = e.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = e.EANs"
        );
    }

    private function addSpecification()
    {
        // product specification for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_text') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('specification') . ",
                pwt.website,
                cpe.entity_id,
                pt.specifications
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN " . $this->getTableName('products_website_temp') . " pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.specifications"
        );
        // product specification  for all web sites
        $this->_doQuery(
            "INSERT INTO " . $this->getTableName('catalog_product_entity_text') . " (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                " . $this->_getProductAttributeId('specification') . ",
                0,
                cpe.entity_id,
                pt.specifications
              FROM " . $this->getTableName('catalog_product_entity') . " cpe
              INNER JOIN " . $this->getTableName('products_temp') . " pt
                  ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.specifications"
        );
    }

    private function addRelatedProducts()
    {
        $this->_doQuery(
            "UPDATE " . $this->getTableName('sinch_related_products') . " srp
                      JOIN " . $this->getTableName('catalog_product_entity') . " cpe
                        ON srp.sinch_product_id = cpe.sinch_product_id
                      SET srp.entity_id = cpe.entity_id"
        );

        $this->_doQuery(
            "UPDATE " . $this->getTableName('sinch_related_products') . " srp
                      JOIN " . $this->getTableName('catalog_product_entity') . " cpe
                        ON srp.related_sinch_product_id = cpe.sinch_product_id
                      SET srp.related_entity_id = cpe.entity_id"
        );

        $results = $this->_doQuery(
            "SELECT link_type_id, code FROM " . $this->getTableName('catalog_product_link_type')
        )->fetchAll();

        $link_type = [];

        foreach ($results as $res) {
            $link_type[$res['code']] = $res['link_type_id'];
        }

        $catalog_product_link = $this->getTableName('catalog_product_link');
        $sinch_related_products = $this->getTableName('sinch_related_products');

        $this->_doQuery(
            "INSERT INTO $catalog_product_link (
                product_id,
                linked_product_id,
                link_type_id
            )(
                SELECT
                    entity_id,
                    related_entity_id,
                    {$link_type['relation']}
                FROM $sinch_related_products
                WHERE sinch_product_id IS NOT NULL
                AND related_sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                product_id = entity_id,
                linked_product_id = related_entity_id"
        );

        $link_attribute_int = $this->getTableName('catalog_product_link_attribute_int');
        $link_attribute_tmp = $this->getTableName('catalog_product_link_attribute_int_tmp');

        $this->_doQuery("DROP TABLE IF EXISTS $link_attribute_tmp");
        $this->_doQuery(
            "CREATE TEMPORARY TABLE $link_attribute_tmp (
                `value_id` int(11) default NULL,
                `product_link_attribute_id` smallint(6) unsigned default NULL,
                `link_id` int(11) unsigned default NULL,
                `value` int(11) NOT NULL default '0',
                    KEY `FK_INT_PRODUCT_LINK_ATTRIBUTE` (`product_link_attribute_id`),
                    KEY `FK_INT_PRODUCT_LINK` (`link_id`)
            )"
        );

        $this->_doQuery(
            "INSERT INTO $link_attribute_tmp (
                product_link_attribute_id,
                link_id,
                value
            )(
                SELECT
                2,
                cpl.link_id,
                0
                FROM $catalog_product_link cpl
            )"
        );

        $this->_doQuery(
            "UPDATE $link_attribute_tmp ct
                JOIN $link_attribute_int c
                    ON ct.link_id=c.link_id
                SET ct.value_id=c.value_id
                WHERE c.product_link_attribute_id=2"
        );

        $this->_doQuery(
            "INSERT INTO $link_attribute_int (
                value_id,
                product_link_attribute_id,
                link_id,
                value
            )(
                SELECT
                value_id,
                product_link_attribute_id,
                link_id,
                value
                FROM $link_attribute_tmp ct
            )
            ON DUPLICATE KEY UPDATE
                link_id=ct.link_id"
        );
    }

    private function replaceMagentoProductsMultistore()
    {
        $this->print("--Replace Magento Products Multistore 1...");

        $products_temp = $this->getTableName('products_temp');
        $products_website_temp = $this->getTableName('products_website_temp');
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $catalog_product_entity_varchar = $this->getTableName('catalog_product_entity_varchar');
        $catalog_category_product = $this->getTableName('catalog_category_product');
        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $core_store = $this->getTableName('store');
        $catalog_product_website = $this->getTableName('catalog_product_website');

        $_defaultAttributeSetId = $this->_getProductDefaulAttributeSetId();

        $attr_atatus = $this->_getProductAttributeId('status');
        $attr_name = $this->_getProductAttributeId('name');
        $attr_visibility = $this->_getProductAttributeId('visibility');
        $attr_tax_class_id = $this->_getProductAttributeId('tax_class_id');
        $attr_image = $this->_getProductAttributeId('image');
        $attr_small_image = $this->_getProductAttributeId('small_image');
        $attr_thumbnail = $this->_getProductAttributeId('thumbnail');

        $this->print("--Replace Magento Multistore 2...");

        //clear products, inserting new products and updating old others.
        $this->_doQuery(
            "DELETE cpe
                FROM $catalog_product_entity cpe
                JOIN $sinch_products_mapping pm
                    ON cpe.entity_id = pm.entity_id
                WHERE pm.shop_sinch_product_id IS NOT NULL
                    AND pm.sinch_product_id IS NULL"
        );

        $this->print("--Replace Magento Multistore 3...");

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity
                (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                a.product_sku,
                NOW(),
                0,
                pt.sinch_product_id
            FROM $products_temp pt
            LEFT JOIN $sinch_products_mapping pm
                ON pt.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = pt.product_sku,
                sinch_product_id = pt.sinch_product_id"
        );

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity
                (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                pt.product_sku,
                NOW(),
                0,
                pt.sinch_product_id
            FROM $products_temp pt
            LEFT JOIN $sinch_products_mapping pm
                ON pt.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = pt.product_sku,
                sinch_product_id = pt.sinch_product_id"
        );

        $this->print("--Replace Magento Multistore 4...");

        //Delete int values for non-existent products
        $this->_doQuery(
            "DELETE cpei
            FROM $catalog_product_entity_int cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                pwt.website,
                cpe.entity_id,
                1
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 1"
        );

        $this->print("--Replace Magento Multistore 5...");

        // set status = 1 for all stores
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                0,
                cpe.entity_id,
                1
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = 1"
        );

        $this->print("--Replace Magento Multistore 6...");
        $this->print("--Replace Magento Multistore 7...");

        $rootCats = $this->getTableName('rootCats');
        $this->_doQuery("DROP TABLE IF EXISTS $rootCats");
        $this->_doQuery(
            "CREATE TABLE $rootCats
            SELECT
                entity_id,
                path,
                SUBSTRING(path, LOCATE('/', path)+1) AS short_path,
                LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1)) AS end_pos,
                SUBSTRING(SUBSTRING(path, LOCATE('/', path)+1), 1, LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1))-1) as rootCat
            FROM $catalog_category_entity"
        );
        $this->_doQuery(
            "UPDATE $rootCats SET rootCat = entity_id WHERE CHAR_LENGTH(rootCat) = 0"
        );

        $this->print("--Replace Magento Multistore 8...");

        //Seems to change mappings for non-existent categories to point to the root cat?
        $this->_doQuery(
            "UPDATE IGNORE $catalog_category_product ccp
            LEFT JOIN $catalog_category_entity cce
                ON ccp.category_id = cce.entity_id
            JOIN $rootCats rc
                ON cce.entity_id = rc.entity_id
            SET ccp.category_id = rc.rootCat
            WHERE cce.entity_id IS NULL"
        );

        $this->print("--Replace Magento Multistore 9...");
        $this->print("--Replace Magento Multistore 10...");

        $catalog_category_product_for_delete_temp = $catalog_category_product . "_for_delete_temp";

        // TEMPORARY
        $this->_doQuery(
            " DROP TABLE IF EXISTS $catalog_category_product_for_delete_temp"
        );
        $this->_doQuery(
            "CREATE TABLE $catalog_category_product_for_delete_temp
            (
                `category_id`       int(10) unsigned NOT NULL default '0',
                `product_id`        int(10) unsigned NOT NULL default '0',
                `store_product_id`  int(10) NOT NULL default '0',
                `store_category_id` int(10) NOT NULL default '0',
                `new_category_id`   int(10) NOT NULL default '0',

                UNIQUE KEY `UNQ_CATEGORY_PRODUCT` (`category_id`,`product_id`),
                KEY `CATALOG_CATEGORY_PRODUCT_CATEGORY` (`category_id`),
                KEY `CATALOG_CATEGORY_PRODUCT_PRODUCT` (`product_id`),
                KEY `CATALOG_NEW_CATEGORY_PRODUCT_CATEGORY` (`new_category_id`)
            )"
        );

        $this->print("--Replace Magento Multistore 11...");

        $this->_doQuery(
            "INSERT INTO $catalog_category_product_for_delete_temp
                (category_id, product_id, store_product_id)
            (SELECT
                ccp.category_id,
                ccp.product_id,
                cpe.sinch_product_id
            FROM $catalog_category_product ccp
            JOIN $catalog_product_entity cpe
                ON ccp.product_id = cpe.entity_id
            WHERE cpe.sinch_product_id IS NOT NULL)"
        );

        $this->print("--Replace Magento Multistore 12...");

        $this->_doQuery(
            "UPDATE $catalog_category_product_for_delete_temp ccpfd
                JOIN $products_temp pt
                    ON ccpfd.store_product_id = pt.sinch_product_id
                SET ccpfd.store_category_id = pt.store_category_id
                WHERE ccpfd.store_product_id != 0"
        );

        $this->print("--Replace Magento Multistore 13...");

        $this->_doQuery(
            "UPDATE $catalog_category_product_for_delete_temp ccpfd
            JOIN $sinch_categories_mapping scm
                ON ccpfd.store_category_id = scm.store_category_id
            SET ccpfd.new_category_id = scm.shop_entity_id
            WHERE ccpfd.store_category_id != 0"
        );

        $this->print("--Replace Magento Multistore 14...");

        $this->_doQuery(
            "DELETE FROM $catalog_category_product_for_delete_temp WHERE category_id = new_category_id"
        );
        $this->_doQuery(
            "DELETE ccp
            FROM $catalog_category_product ccp
            JOIN $catalog_category_product_for_delete_temp ccpfd
                ON ccp.product_id = ccpfd.product_id
                AND ccp.category_id = ccpfd.category_id"
        );

        $this->print("--Replace Magento Multistore 15...");
        $this->print("--Replace Magento Multistore 16 (add multi categories)...");
        $sinch_product_categories = $this->getTableName('sinch_product_categories');

        $this->_doQuery("INSERT INTO $catalog_category_product (category_id, product_id) (
            SELECT scm.shop_entity_id, cpe.entity_id
                FROM $sinch_product_categories spc
            INNER JOIN $catalog_product_entity cpe
                ON spc.store_product_id = cpe.sinch_product_id
            INNER JOIN $sinch_categories_mapping scm
                ON spc.store_category_id = scm.store_category_id
        ) ON DUPLICATE KEY UPDATE product_id = cpe.entity_id, category_id = scm.shop_entity_id");

        $this->print("--Replace Magento Multistore 17...");
        $this->print("--Replace Magento Multistore 18....");
        $this->print("--Replace Magento Multistore 19...");
        $this->print("--Replace Magento Multistore 20...");


        //Delete varchar values for non-existent products
        $this->retriableQuery(
            "DELETE cpev
            FROM $catalog_product_entity_varchar cpev
            LEFT JOIN $catalog_product_entity cpe
                ON cpev.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        //Set product name for specific web sites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                pwt.website,
                cpe.entity_id,
                pt.product_name
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_name"
        );

        $this->print("--Replace Magento Multistore 21...");

        // product name for all web sites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                0,
                cpe.entity_id,
                pt.product_name
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_name"
        );

        $this->print("--Replace Magento Multistore 22...");

        $this->dropHTMLentities($this->_getProductAttributeId('name'));
        $this->addDescriptions();
        $this->cleanProductDistributors();
        $this->addReviews();
        $this->addWeight();
        $this->addPdfUrl();
        $this->addShortDescriptions();
        $this->stockPriceImport->applyDistributors();

        $this->addMetaTitle();
        $this->addMetaDescriptions();
        $this->addEAN();
        $this->addSpecification();
        $this->addManufacturers();

        $this->print("--Replace Magento Multistore 23...");

        //Make product visible to catalog and search (website scope)
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                pwt.website,
                cpe.entity_id,
                4
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 4"
        );

        $this->print("--Replace Magento Multistore 24...");

        //Make product visible to catalog and search (global scope)
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                0,
                cpe.entity_id,
                4
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = 4"
        );

        $this->print("--Replace Magento Multistore 25...");

        try {
            $this->_doQuery(
                "DELETE cpw
                FROM $catalog_product_website cpw
                LEFT JOIN $catalog_product_entity cpe
                    ON cpw.product_id = cpe.entity_id
                WHERE cpe.entity_id IS NULL"
            );
        } catch (DeadlockException $_e) {
            //Do nothing, the foreign key should ensure this is fulfilled anyway
        }

        $this->print("--Replace Magento Multistore 26...");

        //Add products to websites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_website (product_id, website_id)
            (SELECT
                cpe.entity_id,
                pwt.website_id
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                product_id = cpe.entity_id,
                website_id = pwt.website_id"
        );

        $this->print("--Replace Magento Multistore 27...");

        //Adding tax class "Taxable Goods" (website scope)
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                pwt.website,
                cpe.entity_id,
                2
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 2"
        );

        $this->print("--Replace Magento Multistore 28...");

        //Adding tax class "Taxable Goods" (global scope)
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                0,
                cpe.entity_id,
                2
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = 2"
        );

        $this->print("--Replace Magento Multistore 29...");

        // Load url Image
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                store.store_id,
                cpe.entity_id,
                pt.main_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.main_image_url"
        );

        $this->print("--Replace Magento Multistore 30...");

        // image for specific web sites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                0,
                cpe.entity_id,
                pt.main_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.main_image_url"
        );

        $this->print("--Replace Magento Multistore 31...");

        // small_image for specific web sites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                store.store_id,
                cpe.entity_id,
                pt.medium_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.medium_image_url"
        );

        $this->print("--Replace Magento Multistore 32...");

        // small_image for all web sites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                0,
                cpe.entity_id,
                pt.medium_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.medium_image_url"
        );

        $this->print("--Replace Magento Multistore 33...");

        // thumbnail for specific web site
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                store.store_id,
                cpe.entity_id,
                pt.thumb_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.thumb_image_url"
        );

        $this->print("--Replace Magento Multistore 34...");

        // thumbnail for all web sites
        $this->retriableQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                0,
                cpe.entity_id,
                pt.thumb_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.thumb_image_url"
        );

        $this->print("--Replace Magento Multistore 35...");

        $this->addRelatedProducts();
    }

    private function replaceMagentoProductsMultistoreMERGE()
    {
        $this->print("--Replace Magento Multistore 1...");

        $products_temp = $this->getTableName('products_temp');
        $products_website_temp = $this->getTableName('products_website_temp');
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->getTableName('catalog_product_entity_int');
        $catalog_product_entity_varchar = $this->getTableName('catalog_product_entity_varchar');
        $catalog_category_product = $this->getTableName('catalog_category_product');
        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');
        $sinch_products = $this->getTableName('sinch_products');
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $core_store = $this->getTableName('store');
        $catalog_product_website = $this->getTableName('catalog_product_website');

        $_defaultAttributeSetId = $this->_getProductDefaulAttributeSetId();

        $attr_atatus = $this->_getProductAttributeId('status');
        $attr_name = $this->_getProductAttributeId('name');
        $attr_visibility = $this->_getProductAttributeId('visibility');
        $attr_tax_class_id = $this->_getProductAttributeId('tax_class_id');
        $attr_image = $this->_getProductAttributeId('image');
        $attr_small_image = $this->_getProductAttributeId('small_image');
        $attr_thumbnail = $this->_getProductAttributeId('thumbnail');

        $this->print("--Replace Magento Multistore 2...");

        //clear products, inserting new products and updating old others.
        $this->_doQuery(
            "DELETE cpe
                FROM $catalog_product_entity cpe
                JOIN $sinch_products_mapping pm
                    ON cpe.entity_id = pm.entity_id
                WHERE pm.shop_sinch_product_id IS NOT NULL
                    AND pm.sinch_product_id IS NULL"
        );

        $this->print("--Replace Magento Multistore 3...");

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                pt.product_sku,
                NOW(),
                0,
                pt.sinch_product_id
            FROM $products_temp pt
            LEFT JOIN $sinch_products_mapping pm
                ON pt.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = pt.product_sku,
                sinch_product_id = pt.sinch_product_id"
        );

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                pt.product_sku,
                NOW(),
                0,
                pt.sinch_product_id
            FROM $products_temp pt
            LEFT JOIN $sinch_products_mapping pm
                ON pt.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = pt.product_sku,
                sinch_product_id = pt.sinch_product_id"
        );

        $this->print("--Replace Magento Multistore 4...");
        //Set enabled
        $this->_doQuery(
            "DELETE cpei
            FROM $catalog_product_entity_int cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                pwt.website,
                cpe.entity_id,
                1
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 1"
        );

        $this->print("--Replace Magento Multistore 5...");

        // set status = 1 for all stores
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                0,
                cpe.entity_id,
                1
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = 1"
        );

        $this->print("--Replace Magento Multistore 6...");

        //Unifying products with categories.
        $this->_doQuery(
            "DELETE ccp
            FROM $catalog_category_product ccp
            LEFT JOIN $catalog_product_entity cpe
                ON ccp.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        $this->print("--Replace Magento Multistore 7...");

        $rootCats = $this->getTableName('rootCats');

        $this->_doQuery("DROP TABLE IF EXISTS $rootCats");
        $this->_doQuery(
            "CREATE TABLE $rootCats
            SELECT
                entity_id,
                path,
                SUBSTRING(path, LOCATE('/', path)+1) AS short_path,
                LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1)) AS end_pos,
                SUBSTRING(SUBSTRING(path, LOCATE('/', path)+1), 1, LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1))-1) as rootCat
            FROM $catalog_category_entity"
        );
        $this->_doQuery(
            "UPDATE $rootCats SET rootCat = entity_id WHERE CHAR_LENGTH(rootCat) = 0"
        );

        $this->print("--Replace Magento Multistore 8...");

        $this->_doQuery(
            "UPDATE IGNORE $catalog_category_product ccp
                LEFT JOIN $catalog_category_entity cce
                    ON ccp.category_id = cce.entity_id
                JOIN $rootCats rc
                    ON cce.entity_id = rc.entity_id
                SET ccp.category_id = rc.rootCat
                WHERE cce.entity_id IS NULL"
        );

        $this->print("--Replace Magento Multistore 9...");

        $this->_doQuery(
            "DELETE ccp
                FROM $catalog_category_product ccp
                LEFT JOIN $catalog_category_entity cce
                    ON ccp.category_id = cce.entity_id
                WHERE cce.entity_id IS NULL"
        );

        //TODO: This potentially doesn't need the intermediary table (dependent on the worst case scenario of the query)
        $stinch_products_delete = $this->getTableName('stinch_products_delete');

        $this->_doQuery("DROP TABLE IF EXISTS $stinch_products_delete");
        $this->_doQuery(
            "CREATE TABLE $stinch_products_delete
            SELECT cpe.entity_id
            FROM $catalog_product_entity cpe
            WHERE cpe.entity_id NOT IN
            (
                SELECT cpe2.entity_id
                FROM $catalog_product_entity cpe2
                JOIN $sinch_products sp
                ON cpe2.sinch_product_id = sp.sinch_product_id
            )
            AND cpe.type_id = 'simple'
            AND cpe.sinch_product_id IS NOT NULL"
        );
        $this->_doQuery(
            "DELETE cpe FROM $catalog_product_entity cpe JOIN $stinch_products_delete spd USING(entity_id)"
        );

        $this->_doQuery("DROP TABLE IF EXISTS $stinch_products_delete");

        $this->print("--Replace Magento Multistore 10...");
        $this->print("--Replace Magento Multistore 11 (add multi categories)...");
        $sinch_product_categories = $this->getTableName("sinch_product_categories");

        $this->_doQuery("INSERT INTO $catalog_category_product (category_id, product_id) (
            SELECT scm.shop_entity_id, cpe.entity_id
                FROM $sinch_product_categories spc
            INNER JOIN $catalog_product_entity cpe
                ON spc.store_product_id = cpe.sinch_product_id
            INNER JOIN $sinch_categories_mapping scm
                ON spc.store_category_id = scm.store_category_id
        ) ON DUPLICATE KEY UPDATE product_id = cpe.entity_id, category_id = scm.shop_entity_id");

        $this->print("--Replace Magento Multistore 12...");
        $this->print("--Replace Magento Multistore 13...");
        $this->print("--Replace Magento Multistore 14...");
        $this->print("--Replace Magento Multistore 15...");

        //Set product name for specific web sites
        $this->_doQuery(
            "DELETE cpev
            FROM $catalog_product_entity_varchar cpev
            LEFT JOIN $catalog_product_entity cpe
                ON cpev.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                pwt.website,
                cpe.entity_id,
                pt.product_name
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_name"
        );

        $this->print("--Replace Magento Multistore 16...");

        // product name for all web sites
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                0,
                cpe.entity_id,
                pt.product_name
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.product_name"
        );

        $this->print("--Replace Magento Multistore 17...");

        $this->dropHTMLentities($this->_getProductAttributeId('name'));
        $this->addDescriptions();
        $this->cleanProductDistributors();
        if (!$this->_ignore_product_contracts) {
            $this->cleanProductContracts();
        }

        $this->addReviews();
        $this->addWeight();
        $this->addPdfUrl();
        $this->addShortDescriptions();
        $this->stockPriceImport->applyDistributors();
        if (!$this->_ignore_product_contracts) {
            $this->addProductContracts();
        }

        $this->addMetaTitle();
        $this->addMetaDescriptions();
        $this->addEAN();
        $this->addSpecification();
        $this->addManufacturers();

        $this->print("--Replace Magento Multistore 18...");

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                pwt.website,
                cpe.entity_id,
                4
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = w.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 4"
        );

        $this->print("--Replace Magento Multistore 19...");

        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                0,
                cpe.entity_id,
                4
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = 4"
        );

        $this->print("--Replace Magento Multistore 20...");

        $this->_doQuery(
            "DELETE cpw
            FROM $catalog_product_website cpw
            LEFT JOIN $catalog_product_entity cpe
                ON cpw.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        $this->print("--Replace Magento Multistore 21...");

        $this->_doQuery(
            "INSERT INTO $catalog_product_website (product_id, website_id)
            (SELECT
                cpe.entity_id,
                pwt.website_id
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                product_id = cpe.entity_id,
                website_id = pwt.website_id"
        );

        $this->print("--Replace Magento Multistore 22...");

        //Adding tax class "Taxable Goods" (website scope)
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                pwt.website,
                cpe.entity_id,
                2
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 2"
        );

        $this->print("--Replace Magento Multistore 23...");

        //Adding tax class "Taxable Goods" (global scope)
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                0,
                cpe.entity_id,
                2
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                value = 2"
        );

        $this->print("--Replace Magento Multistore 24...");

        // Load url Image
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                store.store_id,
                cpe.entity_id,
                pt.main_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.main_image_url"
        );

        $this->print("--Replace Magento Multistore 25...");

        // image for specific web sites
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                0,
                cpe.entity_id,
                pt.main_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.main_image_url"
        );

        $this->print("--Replace Magento Multistore 26...");

        // small_image for specific web sites
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                store.store_id,
                cpe.entity_id,
                pt.medium_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.medium_image_url"
        );

        $this->print("--Replace Magento Multistore 27...");

        // small_image for all web sites
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                0,
                cpe.entity_id,
                pt.medium_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.medium_image_url"
        );

        $this->print("--Replace Magento Multistore 28...");

        // thumbnail for specific web site
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                store.store_id,
                cpe.entity_id,
                pt.thumb_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.thumb_image_url"
        );

        $this->print("--Replace Magento Multistore 29...");

        // thumbnail for all web sites
        $this->_doQuery(
            "INSERT INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                0,
                cpe.entity_id,
                pt.thumb_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = pt.thumb_image_url"
        );

        $this->print("--Replace Magento Multistore 30...");

        $this->addRelatedProducts();
    }

    private function parseProductsPicturesGallery()
    {
        $parseFile = $this->varDir . FILE_PRODUCTS_PICTURES_GALLERY;
        if (filesize($parseFile)) {
            $this->_log(
                "Start parse " . FILE_PRODUCTS_PICTURES_GALLERY
            );
            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('products_pictures_gallery_temp')
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('products_pictures_gallery_temp') . " (
                    sinch_product_id int(11),
                    image_url varchar(255),
                    thumb_image_url varchar(255),
                    KEY(sinch_product_id)
                )"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                           INTO TABLE " . $this->getTableName('products_pictures_gallery_temp') . "
                           FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                           OPTIONALLY ENCLOSED BY '\"'
                           LINES TERMINATED BY \"\r\n\"
                           IGNORE 1 LINES"
            );

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('sinch_products_pictures_gallery')
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('products_pictures_gallery_temp') . " TO " . $this->getTableName('sinch_products_pictures_gallery')
            );

            $this->_log("Finish parse" . FILE_PRODUCTS_PICTURES_GALLERY);
        } else {
            $this->_log("Wrong file" . $parseFile);
        }
    }

    private function _cleanCateoryProductFlatTable()
    {
        $q = 'SHOW TABLES LIKE "' . $this->getTableName('catalog_product_flat_') . '%"';
        $quer = $this->_doQuery($q)->fetchAll();
        $result = false;
        foreach ($quer as $res) {
            if (is_array($res)) {
                $catalog_product_flat = array_pop($res);
                $this->_doQuery('DELETE pf1 FROM ' . $catalog_product_flat . ' pf1
                    LEFT JOIN ' . $this->getTableName('catalog_product_entity') . ' p
                        ON pf1.entity_id = p.entity_id
                    WHERE p.entity_id IS NULL'
                );
                $this->_log(
                    'cleaned wrong rows from ' . $catalog_product_flat
                );
            }
        }

        return $result;
    }

    private function runIndexer()
    {
        $this->_indexProcessor->reindexAll();
        //Clear changelogs explicitly after finishing a full reindex
        $this->_indexProcessor->clearChangelog();
        //Then make sure all materialized views reflect actual state
        $this->_indexProcessor->updateMview();

        $configTonerFinder = $this->scopeConfig->getValue(
            'sinchimport/general/index_tonerfinder',
            ScopeInterface::SCOPE_STORE);

        if ($configTonerFinder == 1) {
            $this->insertCategoryIdForFinder();
        } else {
            $this->_logImportInfo("Configuration ignores indexing tonerfinder");
        }
    }

    /**
     * @insertCategoryIdForFinder
     */
    public function insertCategoryIdForFinder()
    {
        $tbl_store = $this->getTableName('store');
        $tbl_cat = $this->getTableName('catalog_category_product');

        //TODO: Remove operations on index tables
        $this->_doQuery("INSERT INTO " . $this->getTableName('catalog_category_product_index') . " (
            category_id, product_id, position, is_parent, store_id, visibility) (
                SELECT ccp.category_id, ccp.product_id, ccp.position, 1, store.store_id, 4
                FROM " . $tbl_cat . " ccp
                JOIN " . $tbl_store . " store
            )
            ON DUPLICATE KEY UPDATE visibility = 4"
        );

        foreach ($this->_storeManager->getStores() as $store) {
            $storeId = $store->getId();

            //TODO: Remove operations on index tables
            $table = $this->getTableName('catalog_category_product_index_store' . $storeId);
            if ($this->conn->isTableExists($table)) {
                $this->_doQuery(" 
                  INSERT INTO " . $table . " (category_id, product_id, position, is_parent, store_id, visibility) (
                      SELECT  ccp.category_id, ccp.product_id, ccp.position, 1, store.store_id, 4
                      FROM " . $tbl_cat . " ccp
                        JOIN " . $tbl_store . " store )
                  ON DUPLICATE KEY UPDATE visibility = 4"
                );
            }
        }
    }

    private function invalidateIndexers()
    {
        /**
         * @var IndexerInterface[] $indexers
         */
        $indexers = $this->indexersFactory->create()->getItems();
        foreach ($indexers as $indexer) {
            $indexer->invalidate();
        }
    }

    private function _reindexProductUrlKey()
    {
        $this->_doQuery("DELETE FROM " . $this->getTableName('url_rewrite'));
        $this->_doQuery(
            "UPDATE " . $this->getTableName('catalog_product_entity_varchar') . "
                SET value = ''
            WHERE attribute_id = " . $this->_getProductAttributeId('url_key')
        );

        $this->_productUrlFactory->create()->refreshRewrites();

        return true;
    }

    public function runCleanCache()
    {
        foreach ($this->_cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
            $cacheFrontend->clean();
        }
    }

    public function startCronStockPriceImport()
    {
        $this->_log("Start stock price import from cron");

        $this->import_run_type = 'CRON';
        $this->runStockPriceImport();

        $this->_log("Finish stock price import from cron");
    }

    public function runStockPriceImport()
    {
        $this->initImportStatuses('PRICE STOCK');

        $file_privileg = $this->checkDbPrivileges();
        if (!$file_privileg) {
            $this->_setErrorMessage("LOAD DATA option not set");
            throw new LocalizedException(__("LOAD DATA option not set in the database"));
        }

        $local_infile = $this->checkLocalInFile();
        if (!$local_infile) {
            $this->_setErrorMessage("LOCAL INFILE is not enabled");
            throw new LocalizedException(__("LOCAL INFILE not enabled in the database"));
        }

        if ($this->isImportNotRun() && $this->isFullImportHaveBeenRun()) {
            try {
                $current_vhost = $this->scopeConfig->getValue(
                    'web/unsecure/base_url',
                    ScopeInterface::SCOPE_STORE
                );
                $this->_doQuery("SELECT GET_LOCK('sinchimport_{$current_vhost}', 30)");

                //Once we hold the import lock, check/await indexer completion
                $this->print("Making sure no indexers are currently running");
                if (!$this->sitcIndexMgmt->ensureIndexersNotRunning()) {
                    $this->print("There are indexers currently running, abandoning import");
                    $this->_setErrorMessage("There are indexers currently running, abandoning import");
                    throw new LocalizedException(__("There are indexers currently running, abandoning import"));
                }

                $this->addImportStatus('Stock Price Start Import');

                $this->print("========IMPORTING STOCK AND PRICE========");

                $this->print("Upload Files...");

                $this->files = [
                    FILE_STOCK_AND_PRICES,
                    FILE_PRICE_RULES,
                    FILE_CUSTOMER_GROUPS,
                    FILE_CUSTOMER_GROUP_CATEGORIES,
                    FILE_CUSTOMER_GROUP_PRICE,
                    FILE_DISTRIBUTORS,
                    FILE_DISTRIBUTORS_STOCK_AND_PRICES
                ];

                $this->downloadFiles();
                $this->addImportStatus('Stock Price Upload Files');

                $this->print("Parse Stock And Prices...");
                //Replaces parseStockAndPrices
                $this->stockPriceImport->parse(
                    $this->varDir . FILE_STOCK_AND_PRICES,
                    $this->varDir . FILE_DISTRIBUTORS,
                    $this->varDir . FILE_DISTRIBUTORS_STOCK_AND_PRICES
                );
                $this->stockPriceImport->apply();
                $this->addImportStatus('Stock Price Parse Products');

                $this->print("Apply Customer Group Price...");
                if (file_exists($this->varDir . FILE_CUSTOMER_GROUPS) &&
                    file_exists($this->varDir . FILE_CUSTOMER_GROUP_PRICE)) {
                    $this->customerGroupPrice->parse(
                        $this->varDir . FILE_CUSTOMER_GROUPS,
                        $this->varDir . FILE_CUSTOMER_GROUP_PRICE
                    );
                }

                $this->_eventManager->dispatch(
                    'sinch_pricerules_import_ftp',
                    [
                        'ftp_host' => $this->_dataConf["ftp_server"],
                        'ftp_username' => $this->_dataConf["username"],
                        'ftp_password' => $this->_dataConf["password"]
                    ]
                );

                //Allow the CC category visibility import section to be skipped
                $ccCategoryDisable = $this->scopeConfig->getValue(
                    'sinchimport/category_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccCategoryDisable) {
                    if (file_exists($this->varDir . FILE_CUSTOMER_GROUP_CATEGORIES)) {
                        $this->print("Parsing customer group categories...");
                        $this->customerGroupCatsImport->parse($this->varDir . FILE_CUSTOMER_GROUP_CATEGORIES);
                    }
                } else {
                    $this->print("Skipping custom catalog categories as 'sinchimport/category_visibility/disable_import' is enabled");
                }

                //Allow the CC product visibility import section to be skipped
                $ccProductDisable = $this->scopeConfig->getValue(
                    'sinchimport/product_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccProductDisable) {
                    if (file_exists($this->varDir . FILE_CUSTOMER_GROUP_PRICE) && file_exists($this->varDir . FILE_STOCK_AND_PRICES)) {
                        $this->print("Processing Custom catalog restrictions...");
                        $this->customCatalogImport->parse(
                            $this->varDir . FILE_STOCK_AND_PRICES,
                            $this->varDir . FILE_CUSTOMER_GROUP_PRICE
                        );
                    }
                } else {
                    $this->print("Skipping custom catalog restrictions as 'sinchimport/product_visibility/disable_import' is enabled");
                }

                try {
                    $this->print("Running post import hooks");
                    $this->_eventManager->dispatch(
                        'sinchimport_post_import',
                        [
                            'import_type' => 'PRICE STOCK'
                        ]
                    );
                    $this->print("Post import hooks complete");
                } catch (Exception $e) {
                    $this->print("Caught exception while running post import hooks: " . $e->getMessage());
                }

                //Dead status (actually handled by StockPrice and IndexManagement now)
                $this->addImportStatus('Stock Price Indexing data');

                $this->print("Start cleaning Sinch cache...");
                $this->runCleanCache();
                $this->print("Finish cleaning Sinch cache...");

                $this->addImportStatus('Stock Price Finish import', 1);

                $this->print("========>FINISH STOCK & PRICE SINCH IMPORT");

                $this->_doQuery("SELECT RELEASE_LOCK('sinchimport_{$current_vhost}')");
            } catch (Exception $e) {
                $this->_setErrorMessage($e);
            }
        } else {
            if (!$this->isImportNotRun()) {
                $this->print("--------SINCHIMPORT ALREADY RUN--------");
            } else {
                $this->print("Full import has never finished with success...");
            }
        }
    }

    public function isFullImportHaveBeenRun(): bool
    {
        try {
            $res = $this->_doQuery(
                "SELECT COUNT(*) AS cnt
                FROM " . $this->getTableName('sinch_import_status_statistic') . "
                WHERE import_type='FULL' AND global_status_import='Successful'"
            )->fetch();
        } catch (Zend_Db_Statement_Exception $e) {
            return false; //Assume no import has run if the query fails altogether
        }

        if ($res['cnt'] > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function runReindexUrlRewrite()
    {
        try {
            $this->print("========REINDEX CATALOG URL REWRITE========");

            $this->print("Start indexing catalog url rewrites...");
            $this->_reindexProductUrlKey();
            $this->print("Finish indexing catalog url rewrites...");

            $this->print("========>FINISH REINDEX CATALOG URL REWRITE...");
            $this->_doQuery(
                "INSERT INTO $this->import_status_table (message, finished)
                    VALUES('Indexing data separately', 1)"
            );
        } catch (Exception $e) {
            $this->_setErrorMessage($e);
        }
    }

    /**
     * load Gallery array from XML
     */
    public function loadGalleryPhotos($entity_id)
    {
        $sinch_product_id = $this->getSinchProductIdByEntity($entity_id);
        if (!$sinch_product_id) {
            return $this;
        }
        $res = $this->_doQuery(
            "SELECT COUNT(*) AS cnt
                FROM " . $this->getTableName('sinch_products_pictures_gallery') . "
                WHERE sinch_product_id = " . $sinch_product_id,
            true
        )->fetch();

        if (!$res || !$res['cnt']) {
            return $this;
        }

        $photos = $this->_doQuery(
            "SELECT image_url as Pic, thumb_image_url as ThumbPic
                FROM " . $this->getTableName('sinch_products_pictures_gallery') . "
                WHERE sinch_product_id = " . $sinch_product_id,
            true
        )->fetchAll();

        foreach ($photos as $photo) {
            $picHeight = (int)500;
            $picWidth = (int)500;
            $thumbUrl = (string)$photo["ThumbPic"];
            $picUrl = (string)$photo["Pic"];

            array_push(
                $this->galleryPhotos,
                [
                    'height' => $picHeight,
                    'width' => $picWidth,
                    'thumb' => $thumbUrl,
                    'pic' => $picUrl
                ]
            );
        }

        return $this;
    }

    private function getSinchProductIdByEntity($entity_id)
    {
        $res = $this->_doQuery(
            "SELECT sinch_product_id FROM " . $this->getTableName('sinch_products_mapping') . " WHERE entity_id = " . $entity_id,
            true
        )->fetch();

        return ($res['sinch_product_id']);
    }

    public function getGalleryPhotos()
    {
        return $this->galleryPhotos;
    }

    public function getImportStatusHistory()
    {
        $res = $this->_doQuery(
            "SELECT COUNT(*) as cnt FROM " . $this->import_status_statistic_table
        )->fetch();
        $cnt = $res['cnt'];

        $StatusHistory_arr = [];
        if ($cnt > 0) {
            $a = (($cnt > 7) ? ($cnt - 7) : 0);
            $b = $cnt;

            $result = $this->_doQuery(
                "SELECT
                        id,
                        start_import,
                        finish_import,
                        import_type,
                        number_of_products,
                        global_status_import,
                        detail_status_import
                    FROM " . $this->import_status_statistic_table . "
                    ORDER BY start_import limit " . $a . ", " . $b,
                true
            )->fetchAll();

            foreach ($result as $res) {
                $StatusHistory_arr[] = $res;
            }
        }

        return $StatusHistory_arr;
    }

    public function getDateOfLatestSuccessImport(): string
    {
        $imp_date = $this->_doQuery(
            "SELECT start_import, finish_import
                FROM " . $this->import_status_statistic_table . "
                WHERE global_status_import = 'Successful'
                ORDER BY id DESC LIMIT 1",
            true
        )->fetch();

        return is_array($imp_date) ? $imp_date['start_import'] : "N/A";
    }

    public function getImportStatuses(): array
    {
        $messages = [];
        if (!$this->conn->isTableExists($this->import_status_table)) {
            return $messages;
        }

        $res = $this->_doQuery(
            "SELECT id, message, finished
                FROM " . $this->import_status_table . "
                ORDER BY id ASC"
        )->fetchAll();

        if (!empty($res)) {
            foreach ($res as $message) {
                $messages[] = [
                    'id' => $message['id'],
                    'message' => $message['message'],
                    'finished' => $message['finished']
                ];
            }
        }

        return $messages;
    }
}
