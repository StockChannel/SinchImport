<?php

namespace SITC\Sinchimport\Model;

use DateTime;
use Exception;
use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use SITC\Sinchimport\Helper\Data;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Helper\Url;
use SITC\Sinchimport\Logger\Logger;
use SITC\Sinchimport\Model\Import\AccountGroupCategories;
use SITC\Sinchimport\Model\Import\AccountGroupPrice;
use SITC\Sinchimport\Model\Import\Attributes;
use SITC\Sinchimport\Model\Import\Brands;
use SITC\Sinchimport\Model\Import\BulletPoints;
use SITC\Sinchimport\Model\Import\CustomCatalogVisibility;
use SITC\Sinchimport\Model\Import\EANCodes;
use SITC\Sinchimport\Model\Import\Families;
use SITC\Sinchimport\Model\Import\IndexManagement;
use SITC\Sinchimport\Model\Import\Multimedia;
use SITC\Sinchimport\Model\Import\Popularity;
use SITC\Sinchimport\Model\Import\ProductDates;
use SITC\Sinchimport\Model\Import\ReasonsToBuy;
use SITC\Sinchimport\Model\Import\RelatedProducts;
use SITC\Sinchimport\Model\Import\Reviews;
use SITC\Sinchimport\Model\Import\StockPrice;
use SITC\Sinchimport\Model\Import\UNSPSC;
use SITC\Sinchimport\Model\Import\VirtualCategory;
use SITC\Sinchimport\Model\Product\UrlFactory;
use Symfony\Component\Console\Output\ConsoleOutput;
use Zend_Db_Statement_Exception;
use Zend_Db_Statement_Interface;

class Sinch {
    public const FIELD_TERMINATED_CHAR = "|";
    private const UPDATE_CATEGORY_DATA = false;

    public bool $debug_mode = false;
    protected ManagerInterface $_eventManager;
    protected StoreManagerInterface $_storeManager;
    protected ScopeConfigInterface $scopeConfig;
    protected Logger $_sinchLogger;
    protected ResourceConnection $_resourceConnection;
    protected AdapterInterface $conn;
    protected Attribute $_eavAttribute;
    private ConsoleOutput $output;
    private array $galleryPhotos = [];
    private int $defaultAttributeSetId = 0;
    private string $field_terminated_char;
    private string $import_status_table;
    private string $import_status_statistic_table;
    private int $current_import_status_statistic_id;
    private ?int $_categoryEntityTypeId = null;
    private ?int $_categoryDefault_attribute_set_id = null;
    private string $import_run_type = 'MANUAL';
    private bool $_ignore_product_related = false;
    private ?int $_categoryMetaTitleAttrId = null;
    private ?int $_categoryMetadescriptionAttrId = null;
    private ?int $_categoryDescriptionAttrId = null;
    private mixed $_dataConf;
    private $_deploymentData;

    private string $categoryImportMode;
    private string $productImportMode;

    //Nick
    private IndexManagement $sitcIndexMgmt;
    private Attributes $attributesImport;
    private AccountGroupCategories $customerGroupCatsImport;
    private AccountGroupPrice $customerGroupPrice;
    private UNSPSC $unspscImport;
    private CustomCatalogVisibility $customCatalogImport;
    private StockPrice $stockPriceImport;
    private Brands $brandImport;
    private Multimedia $multimediaImport;
    private EANCodes $eanImport;
    private BulletPoints $bulletPointsImport;
    private Families $familiesImport;
    private ReasonsToBuy $reasonsToBuyImport;
    private ProductDates $datesImport;
    private Popularity $popularityImport;
    private VirtualCategory $virtualCategoryImport;
    private Reviews $reviewImport;
    private RelatedProducts $relatedProductsImport;

    private Download $dlHelper;
    private Data $dataHelper;
    private Url $helperUrl;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        Logger $sinchLogger,
        ResourceConnection $resourceConnection,
        DeploymentConfig $deploymentConfig,
        Attribute $eavAttribute,
        ConsoleOutput $output,
        IndexManagement $sitcIndexMgmt,
        Attributes $attributesImport,
        AccountGroupCategories $customerGroupCatsImport,
        AccountGroupPrice $customerGroupPrice,
        UNSPSC $unspscImport,
        CustomCatalogVisibility $customCatalogImport,
        StockPrice $stockPriceImport,
        Brands $brandImport,
        Multimedia $multimediaImport,
        EANCodes $eanImport,
        BulletPoints $bulletPointsImport,
        Families $familiesImport,
        ReasonsToBuy $reasonsToBuyImport,
        ProductDates $datesImport,
        Popularity $popularityImport,
        VirtualCategory $virtualCategoryImport,
        Reviews $reviewImport,
        RelatedProducts $relatedProductsImport,
        Download $dlHelper,
        Data $dataHelper,
        Url $helperUrl
    )
    {
        $this->sitcIndexMgmt = $sitcIndexMgmt;
        $this->attributesImport = $attributesImport;
        $this->customerGroupCatsImport = $customerGroupCatsImport;
        $this->customerGroupPrice = $customerGroupPrice;
        $this->unspscImport = $unspscImport;
        $this->customCatalogImport = $customCatalogImport;
        $this->stockPriceImport = $stockPriceImport;
        $this->brandImport = $brandImport;
        $this->multimediaImport = $multimediaImport;
        $this->eanImport = $eanImport;
        $this->bulletPointsImport = $bulletPointsImport;
        $this->familiesImport = $familiesImport;
        $this->reasonsToBuyImport = $reasonsToBuyImport;
        $this->datesImport = $datesImport;
        $this->popularityImport = $popularityImport;
        $this->virtualCategoryImport = $virtualCategoryImport;
        $this->reviewImport = $reviewImport;
        $this->relatedProductsImport = $relatedProductsImport;

        $this->dlHelper = $dlHelper;
        $this->dataHelper = $dataHelper;
        $this->helperUrl = $helperUrl;

        $this->output = $output;
        $this->scopeConfig = $scopeConfig;
        $this->_sinchLogger = $sinchLogger->withName("SinchImport");
        $this->_resourceConnection = $resourceConnection;
        $this->_eventManager = $context->getEventDispatcher();
        $this->conn = $this->_resourceConnection->getConnection();
        $this->_eavAttribute = $eavAttribute;

        $this->import_status_table = $this->getTableName('sinch_import_status');
        $this->import_status_statistic_table = $this->getTableName('sinch_import_status_statistic');

        $this->_dataConf = $this->scopeConfig->getValue(
            'sinchimport/sinch_ftp',
            ScopeInterface::SCOPE_STORE
        );
        $this->categoryImportMode = $this->_dataConf['replace_category'];
        $this->productImportMode = $this->_dataConf['replace_product'];

        $this->_deploymentData = $deploymentConfig->getConfigData();

        $this->field_terminated_char = self::FIELD_TERMINATED_CHAR;
    }

    private function getTableName(string $tableName): string
    {
        return $this->_resourceConnection->getTableName($tableName);
    }

    /**
     * @throws Exception
     */
    public function startCronFullImport(): void
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
    private function _log(string $logString): void
    {
        $this->_sinchLogger->info($logString);
    }

    /**
     * Backup the IDs of the products and categories for reuse
     * @return void
     */
    private function backupIDs(): void
    {
        // Ensure that the backup tables are empty so they're ignored by the rest of the process,
        // even if they happened to contain data
        $sinch_product_backup = $this->conn->getTableName('sinch_product_backup');
        $sinch_category_backup = $this->conn->getTableName('sinch_category_backup');

        $conn = $this->conn->getConnection();
        // Clear any data currently in the backup tables
        /** @noinspection SqlWithoutWhere */
        $conn->query(
            "DELETE FROM $sinch_product_backup"
        );
        /** @noinspection SqlWithoutWhere */
        $conn->query(
            "DELETE FROM $sinch_category_backup"
        );

        if ($this->dataHelper->getStoreConfig('sinchimport/sinch_ftp/backup_data') == 1) {
            $this->print("Backing up product and category IDs for reuse");
            $catalog_product_entity = $this->conn->getTableName('catalog_product_entity');
            $catalog_category_entity = $this->conn->getTableName('catalog_category_entity');

            // Backup the current IDs to the tables
            $conn->query(
                "INSERT INTO $sinch_product_backup (entity_id, sku, sinch_product_id)
                SELECT entity_id, sku, sinch_product_id FROM $catalog_product_entity"
            );
            // Don't think we need to have attribute_set_id
            $conn->query(
                "INSERT INTO $sinch_category_backup (entity_id, parent_id, store_category_id, parent_store_category_id)
                SELECT entity_id, parent_id, store_category_id, parent_store_category_id
                FROM $catalog_category_entity"
            );
        }
    }

    /**
     * @throws Exception
     */
    public function runSinchImport(): void
    {
        $this->_categoryMetaTitleAttrId = $this->dataHelper->getCategoryAttributeId('meta_title');
        $this->_categoryMetadescriptionAttrId = $this->dataHelper->getCategoryAttributeId('meta_description');
        $this->_categoryDescriptionAttrId = $this->dataHelper->getCategoryAttributeId('description');

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

        if ($this->canImport()) {
            $current_vhost = $this->scopeConfig->getValue(
                'web/unsecure/base_url',
                ScopeInterface::SCOPE_STORE
            );
            try {
                $this->_doQuery("SELECT GET_LOCK('sinchimport_{$current_vhost}', 30)");
                $this->print("Import lock acquired");
                $this->addImportStatus('Start Import');
                //Once we hold the import lock, check/await indexer completion
                $this->print("Making sure no indexers are currently running");
                if (!$this->sitcIndexMgmt->ensureIndexersNotRunning()) {
                    $this->print("There are indexers currently running, abandoning import");
                    $this->_setErrorMessage("There are indexers currently running, abandoning import");
                    $this->setImportResult('Abandoned');
                    throw new LocalizedException(__("There are indexers currently running, abandoning import"));
                }
                $this->addImportStatus('Start Import', true);

                $this->print("========IMPORTING DATA IN {$this->categoryImportMode} MODE========");

                $requiredFiles = [
                    Download::FILE_CATEGORIES,
                    Download::FILE_DISTRIBUTORS,
                    Download::FILE_DISTRIBUTORS_STOCK,
                    Download::FILE_PRODUCT_CATEGORIES,
                    Download::FILE_PRODUCTS,
                    Download::FILE_STOCK_AND_PRICES,
                    Download::FILE_PRODUCTS_GALLERY_PICTURES
                ];
                $optionalFiles = [
                    Download::FILE_ACCOUNT_GROUP_CATEGORIES,
                    Download::FILE_ACCOUNT_GROUPS,
                    Download::FILE_ACCOUNT_GROUP_PRICE,
                    Download::FILE_RELATED_PRODUCTS,
                    Download::FILE_CATEGORIES_FEATURES,
                    Download::FILE_PRODUCT_FEATURES,
                    Download::FILE_RESTRICTED_VALUES,
                    Download::FILE_BRANDS,
                    Download::FILE_MULTIMEDIA,
                    Download::FILE_BULLET_POINTS,
                    Download::FILE_FAMILIES,
                    Download::FILE_FAMILY_SERIES,
                    Download::FILE_REASONS_TO_BUY,
                    Download::FILE_REVIEWS
                ];

                $this->addImportStatus('Download Files');
                $this->downloadFiles($requiredFiles, $optionalFiles);
                $this->addImportStatus('Download Files', true);

                // Backup IDs if its enabled, otherwise clear the backup tables to ensure it doesn't fuck with
                // later data processing
                $this->backupIDs();

                $this->addImportStatus('Parse Categories');
                $this->parseCategories();
                $this->addImportStatus('Parse Categories', true);

                if ($this->virtualCategoryImport->haveRequiredFiles()) {
                    $this->print("Parsing Virtual Categories");
                    $this->virtualCategoryImport->parse();
                }

                $this->addImportStatus('Parse Stock and Prices');
                if (!$this->stockPriceImport->haveRequiredFiles()) {
                    $this->_setErrorMessage('Missing required files for Stock price import section, or some files failed validation');
                    throw new LocalizedException(__("Missing required files for stock price section"));
                }
                $this->stockPriceImport->parse();
                $this->addImportStatus('Parse Stock and Prices', true);

                if ($this->brandImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parse Manufacturers');
                    $this->brandImport->parse();
                    $this->addImportStatus('Parse Manufacturers', true);
                }

                $this->addImportStatus('Parse Related Products');
                if ($this->relatedProductsImport->haveRequiredFiles()) {
                    $this->relatedProductsImport->parse();
                }
                $this->addImportStatus('Parse Related Products', true);


                if ($this->attributesImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parse Product Features');
                    $this->attributesImport->parse();
                    $this->addImportStatus('Parse Product Features', true);
                } else {
                    $this->print("Missing required files for attributes import section, or downloaded files failed validation, skipping");
                }


                $this->addImportStatus('Parse Product Categories');
                $this->parseProductCategories();
                $this->addImportStatus('Parse Product Categories', true);

                $this->addImportStatus('Parse Products');
                $this->parseProducts();
                $this->addImportStatus('Parse Products', true);

                $this->addImportStatus('Parse Pictures Gallery');
                $this->parseProductsPicturesGallery();
                $this->addImportStatus('Parse Pictures Gallery', true);

                //Moved here (from the end of replaceMagentoProductsMultistore) to make the flow easier to follow
                // (and to make sure it runs after the full mapping)
                $this->addImportStatus('Apply Related Products');
                if ($this->relatedProductsImport->haveRequiredFiles()) {
                    $this->relatedProductsImport->apply();
                }
                $this->addImportStatus('Apply Related Products', true);

                if ($this->attributesImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parse Restricted Values');
                    $this->attributesImport->applyAttributeValues();
                    $this->addImportStatus('Parse Restricted Values', true);
                }

                if ($this->bulletPointsImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parsing Bullet points');
                    $this->bulletPointsImport->parse();
                    $this->addImportStatus('Parsing Bullet points', true);
                    $this->addImportStatus('Applying Bullet points');
                    $this->bulletPointsImport->apply();
                    $this->addImportStatus('Applying Bullet points', true);
                }

                if ($this->familiesImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parsing Families');
                    $this->familiesImport->parse();
                    $this->addImportStatus('Parsing Families', true);
                    $this->addImportStatus('Applying Families');
                    $this->familiesImport->apply();
                    $this->addImportStatus('Applying Families', true);
                }

                if ($this->reasonsToBuyImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parsing Reasons to Buy');
                    $this->reasonsToBuyImport->parse();
                    $this->addImportStatus('Parsing Reasons to Buy', true);
                    $this->addImportStatus('Applying Reasons to Buy');
                    $this->reasonsToBuyImport->apply();
                    $this->addImportStatus('Applying Reasons to Buy', true);
                }

                if ($this->datesImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parsing Product Release and EOL Dates');
                    $this->datesImport->parse();
                    $this->addImportStatus('Parsing Product Release and EOL Dates', true);
                }

                if ($this->popularityImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parsing Product Popularity Scores');
                    $this->popularityImport->parse();
                    $this->addImportStatus('Parsing Product Popularity Scores', true);
                }

                if ($this->reviewImport->haveRequiredFiles()) {
                    $this->addImportStatus('Parse Reviews');
                    $this->reviewImport->parse();
                    $this->addImportStatus('Parse Reviews', true);
                }

                $this->addImportStatus('Parse Stock And Prices');
                //Replaced parseStockAndPrices
                $this->stockPriceImport->apply();
                $this->addImportStatus('Parse Stock And Prices', true);


                if ($this->customerGroupPrice->haveRequiredFiles()) {
                    $this->addImportStatus('Apply Account Group Price');
                    $this->customerGroupPrice->parse();
                    $this->addImportStatus('Apply Account Group Price', true);
                } else {
                    $this->print("Missing required files for account group price section, or downloaded files failed validation, skipping");
                }

                //Allow the CC category visibility import section to be skipped
                $ccCategoryDisable = $this->scopeConfig->getValue(
                    'sinchimport/category_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccCategoryDisable) {
                    if ($this->customerGroupCatsImport->haveRequiredFiles()) {
                        $this->addImportStatus('Parsing account group categories');
                        $this->customerGroupCatsImport->parse();
                        $this->addImportStatus('Parsing account group categories', true);
                    } else {
                        $this->print("Missing required files for account group categories section, or downloaded files failed validation, skipping");
                    }
                } else {
                    $this->print("Skipping custom catalog categories as 'sinchimport/category_visibility/disable_import' is enabled");
                }

                $this->addImportStatus('Applying UNSPSC values');
                $this->unspscImport->apply();
                $this->addImportStatus('Applying UNSPSC values', true);

                //Allow the CC product visibility import section to be skipped
                $ccProductDisable = $this->scopeConfig->getValue(
                    'sinchimport/product_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccProductDisable) {
                    if ($this->customCatalogImport->haveRequiredFiles()) {
                        $this->addImportStatus('Processing Custom catalog restrictions');
                        $this->customCatalogImport->parse();
                        $this->addImportStatus('Processing Custom catalog restrictions', true);
                    } else {
                        $this->print("Missing required files for custom catalog section, or downloaded files failed validation, skipping");
                    }
                } else {
                    $this->print("Skipping custom catalog restrictions as 'sinchimport/product_visibility/disable_import' is enabled");
                }

                try {
                    $this->addImportStatus('Post import hooks');
                    $this->_eventManager->dispatch('sinchimport_post_import', ['import_type' => 'FULL']);
                    $this->addImportStatus('Post import hooks', true);
                } catch (Exception $e) {
                    $this->addImportStatus('Post import hooks', true);
                    $this->print("Caught exception while running post import hooks: " . $e->getMessage());
                }

                if (!$this->dataHelper->indexSeparately()) {
                    $this->addImportStatus('Run indexing');
                    $this->sitcIndexMgmt->runFullIndex();
                    $this->addImportStatus('Run indexing', true);
                } else {
                    $this->addImportStatus('Invalidate indexes');
                    $this->sitcIndexMgmt->invalidateIndexers();
                    $this->addImportStatus('Invalidate indexes', true);
                }

                $this->addImportStatus('Index catalog url rewrites');
                $this->sitcIndexMgmt->reindexProductUrls();
                $this->helperUrl->generateCategoryUrl();
                $this->addImportStatus('Index catalog url rewrites', true);

                $this->addImportStatus('Clean cache');
                $this->sitcIndexMgmt->clearCaches();
                $this->addImportStatus('Clean cache', true);


                try {
                    $this->addImportStatus('Import completion hooks');
                    $this->_eventManager->dispatch(
                        'sinchimport_import_complete_post_index',
                        ['import_type' => 'FULL']
                    );
                    $this->addImportStatus('Import completion hooks', true);
                } catch (Exception $e) {
                    $this->addImportStatus('Import completion hooks', true);
                    $this->print("Caught exception while running import completion hooks: " . $e->getMessage());
                }

                $this->addImportStatus('Finish Import', true);
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

    private function initImportStatuses($type): void
    {
        $this->conn->query("TRUNCATE TABLE {$this->import_status_table}");

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
    private function _setErrorMessage(string $message): void
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

    /**
     * Return whether we can start an import right now
     * @return bool true if no import is running (and thus we can safely start one)
     */
    public function canImport(): bool
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
    private function print(string $message): void
    {
        $this->output->writeln($message);
        $this->_log($message);
    }

    /**
     * Print message along with completion state to the screen and to the status table
     * @param string $message Message
     * @param bool $finished Whether the status entry is complete
     */
    private function addImportStatus(string $message, bool $finished = false): void
    {
        $this->print("-- " . $message . ($finished ? " - Done" : ""));
        $this->conn->query(
            "INSERT INTO {$this->import_status_table} (message, finished)
                    VALUES(:msg, :finished)
                    ON DUPLICATE KEY UPDATE finished = :finished",
            [":msg" => $message, ":finished" => $finished]
        );
        $this->conn->query(
            "UPDATE {$this->import_status_statistic_table} SET detail_status_import = :msg WHERE id = :importId",
            [":importId" => $this->current_import_status_statistic_id, ":msg" => $message]
        );
        if ($message == 'Finish Import' && $finished) {
            $this->setImportResult('Successful');
        }
    }

    private function setImportResult(string $status): void
    {
        $this->_resourceConnection->getConnection()->query(
            "UPDATE $this->import_status_statistic_table
                SET global_status_import = :status, finish_import = NOW()
                WHERE id = :currentImportId",
            [":currentImportId" => $this->current_import_status_statistic_id, ":status" => $status]
        );
    }

    /**
     * Download the required files for this import
     * @param array $requiredFiles Required Files
     * @param array $optionalFiles Optional Files (permit failure)
     * @throws LocalizedException
     */
    private function downloadFiles(array $requiredFiles, array $optionalFiles = []): void
    {
        $this->_log("--- Start downloading files ---");

        //Ensure the save directory exists
        $this->dlHelper->createSaveDir();

        $connRes = $this->dlHelper->connect();
        if ($connRes !== true) {
            $this->_setErrorMessage($connRes);
            throw new LocalizedException(__($connRes));
        }

        try {
            //Download required files, then optional ones (favour priority)
            $this->_log("Downloading required files");
            foreach ($requiredFiles as $filename) {
                if (!$this->dlHelper->downloadFile($filename)) {
                    $this->_setErrorMessage("$filename is empty, cannot continue");
                    throw new LocalizedException(__("$filename is empty, cannot continue"));
                }
                if (!$this->dlHelper->validateFile($filename)) {
                    $this->_setErrorMessage("$filename is not valid for nile format, cannot continue");
                    throw new LocalizedException(__("$filename is not valid for nile format, cannot continue"));
                }
            }
            $this->_log("Downloading optional files");
            foreach ($optionalFiles as $filename) {
                if (!$this->dlHelper->downloadFile($filename)) {
                    //Allow failures of optional files, turn off their features
                    switch ($filename) {
                        case Download::FILE_RELATED_PRODUCTS:
                            $this->_ignore_product_related = true;
                            break;
                        default:
                    }
                    $this->print("Failed to download optional file $filename, skipping");
                    continue;
                }
                if (!$this->dlHelper->validateFile($filename)) {
                    // Do the same if they fail to validate
                    switch ($filename) {
                        case Download::FILE_RELATED_PRODUCTS:
                            $this->_ignore_product_related = true;
                            break;
                        default:
                    }
                    $this->print("Failed to validate optional file $filename, skipping");
                }
            }
        } finally {
            $this->dlHelper->disconnect();
        }
        $this->_log("--- Finished downloading files ---");
    }

    /**
     * @throws LocalizedException
     */
    private function parseCategories(): void
    {
        if (!$this->dlHelper->validateFile(Download::FILE_CATEGORIES)) {
            $this->_log(Download::FILE_CATEGORIES . " failed validation");
            throw new LocalizedException(__(Download::FILE_CATEGORIES . ' failed validation'));
        }
        $this->_log("Start parse " . Download::FILE_CATEGORIES);

        $this->_getCategoryEntityTypeIdAndDefault_attribute_set_id();

        $_categoryDefault_attribute_set_id
            = $this->_categoryDefault_attribute_set_id;

        $name_attrid = $this->dataHelper->getCategoryAttributeId('name');
        $is_anchor_attrid = $this->dataHelper->getCategoryAttributeId('is_anchor');
        $image_attrid = $this->dataHelper->getCategoryAttributeId('image');

        $attr_url_key = $this->dataHelper->getCategoryAttributeId('url_key');
        $attr_display_mode = $this->dataHelper->getCategoryAttributeId('display_mode');
        $attr_is_active = $this->dataHelper->getCategoryAttributeId('is_active');
        $attr_include_in_menu = $this->dataHelper->getCategoryAttributeId('include_in_menu');

        $categories_temp = $this->getTableName('categories_temp');
        $this->_doQuery("DROP TABLE IF EXISTS $categories_temp");

        $this->_doQuery(
            "CREATE TABLE $categories_temp (
                    store_category_id              INT(11),
                    parent_store_category_id       INT(11),
                    category_name                  VARCHAR(50),
                    order_number                   INT(11),
                    include_in_menu                TINYINT(1) NOT NULL DEFAULT 1,
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
                    VirtualCategory                VARCHAR(255) DEFAULT NULL,
                    is_anchor                      TINYINT(1) NOT NULL DEFAULT 1,
                    KEY(store_category_id),
                    KEY(parent_store_category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );

        //ID|ParentID|Name|Order|IsHidden|ProductCount|SubCategoryProductCount|ThumbImageURL|NestLevel|SubCategoryCount|UNSPSC|TypeID|MainImageURL|MetaTitle|MetaDescription|Description|VirtualCategory
        $this->_doQuery(
            "LOAD DATA LOCAL INFILE :categoriesCsv
                INTO TABLE $categories_temp
                FIELDS TERMINATED BY '{$this->field_terminated_char}'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (
                    store_category_id,
                    parent_store_category_id,
                    category_name,
                    order_number,
                    @hidden,
                    products_within_this_category,
                    products_within_sub_categories,
                    categories_image,
                    @level,
                    children_count,
                    UNSPSC,
                    RootName,
                    MainImageURL,
                    MetaTitle,
                    MetaDescription,
                    Description,
                    VirtualCategory
                )
                SET include_in_menu = IF(UCASE(@hidden) = 'TRUE', 0, 1),
                    level = IF(@level >= 0, @level + 2, @level)",
            [":categoriesCsv" => $this->dlHelper->getSavePath(Download::FILE_CATEGORIES)]
        );

        $rootCatNames = $this->getDistinctRootCatNames();

        if (count($rootCatNames) >= 1) { // multistore logic

            $this->print("==========MULTI STORE LOGIC==========");

            switch ($this->categoryImportMode) {
                case "REWRITE":
                    $this->rewriteMultistoreCategories(
                        $rootCatNames,
                        $_categoryDefault_attribute_set_id,
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
            $this->print("No root categories found");
            throw new LocalizedException(__('Did not find any root categories'));
        }

        $this->_log("Finish parse " . Download::FILE_CATEGORIES);
        $this->_set_default_rootCategory();

    }

    private function _getCategoryEntityTypeIdAndDefault_attribute_set_id(): void
    {
        if (!$this->_categoryEntityTypeId || !$this->_categoryDefault_attribute_set_id) {
            $result = $this->conn->fetchRow(
                "SELECT entity_type_id, default_attribute_set_id
                    FROM {$this->getTableName('eav_entity_type')}
                    WHERE entity_type_code = 'catalog_category'
                    LIMIT 1"
            );
            if ($result) {
                $this->_categoryEntityTypeId = $result['entity_type_id'];
                $this->_categoryDefault_attribute_set_id = $result['default_attribute_set_id'];
            }
        }
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

    private function tableHasData($table): bool
    {
        $tableRowCount = (int)$this->conn->fetchOne("SELECT COUNT(*) FROM $table");
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
        $name_attrid,
        $attr_display_mode,
        $attr_url_key,
        $attr_include_in_menu,
        $attr_is_active,
        $image_attrid,
        $is_anchor_attrid
    ){
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
            $name_attrid
        );

        $this->print("    --Add category data...");
        $this->addCategoryData(
            $_categoryDefault_attribute_set_id,
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
                    (2, :nameAttr, :storeId, 1, 'Root Catalog'),
                    (3, :urlKeyAttr, 0, 1, 'root-catalog')",
            [":nameAttr" => $name_attrid, ":urlKeyAttr" => $attr_url_key, ":storeId" => $this->dataHelper->getDefaultStoreId()]
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
                        (:nameAttr,       :storeId, :entityId, :value),
                        (:displayModeAttr, :storeId, :entityId, :value),
                        (:urlKeyAttr,      0, :entityId, :value)",
                [
                    ":nameAttr" => $name_attrid,
                    ":displayModeAttr" => $attr_display_mode,
                    ":urlKeyAttr" => $attr_url_key,
                    ":entityId" => $i,
                    ":value" => "$key",
                    ":storeId" => $this->dataHelper->getDefaultStoreId()
                ] //TODO: Why is value inserted into display_mode?
            );

            $this->_doQuery(
                "INSERT $catalog_category_entity_int
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        (:isActiveAttr, 0, :entityId, 1),
                        (:isActiveAttr, :storeId, :entityId, 1),
                        (:includeInMenuAttr, 0, :entityId, 1),
                        (:includeInMenuAttr, :storeId, :entityId, 1)",
                [
                    ":isActiveAttr" => $attr_is_active,
                    ":includeInMenuAttr" => $attr_include_in_menu,
                    ":entityId" => $i,
                    ":storeId" => $this->dataHelper->getDefaultStoreId()
                ]
            );
            $i++;
        }
    }

    //TODO: Remove pointless attribute ids passed as args
    private function mapSinchCategories($name_attrid, $mapping_again = false): void
    {
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $sinch_categories_mapping_temp = $this->getTableName('sinch_categories_mapping_temp');
        $catalog_category_entity = $this->getTableName('catalog_category_entity');
        $catalog_category_entity_varchar = $this->getTableName('catalog_category_entity_varchar');
        $categories_temp = $this->getTableName('categories_temp');

        $this->createCategoryMappingSinchTables();

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

        //The following 5 lines replace 100 lines of repetition from previous implementations
        $catalog_category_entity_source = $this->getTableName('sinch_category_backup');
        //When the import type is merge, we're mapping again, or the backup table is empty, use catalog_category_entity
        if ($this->categoryImportMode == "MERGE" || $mapping_again || !$this->tableHasData($catalog_category_entity_source)) {
            $catalog_category_entity_source = $catalog_category_entity;
        }

        //Ensure that only the lowest ID category for a given store_category_id exists
        $this->_doQuery(
            "DELETE cce FROM $catalog_category_entity_source cce
                WHERE cce.store_category_id IS NOT NULL
                  AND entity_id != (
                      SELECT MIN(entity_id) FROM (SELECT * FROM $catalog_category_entity_source) cce2
                      WHERE cce2.store_category_id = cce.store_category_id
                  )"
        );

        $this->_doQuery(
            "INSERT IGNORE INTO $sinch_categories_mapping_temp
                (
                    shop_entity_id,
                    shop_parent_id,
                    shop_store_category_id,
                    shop_parent_store_category_id
                )
            (SELECT
                entity_id,
                parent_id,
                store_category_id,
                parent_store_category_id
            FROM $catalog_category_entity_source)"
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
            JOIN $catalog_category_entity_source cce
                ON cmt.parent_store_category_id = cce.store_category_id
            SET cmt.shop_parent_id = cce.entity_id"
        );

        // Update the mapping with the correct parent info for each root
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

        // added for mapping new sinch categories in merge && !UPDATE_CATEGORY_DATA mode
        if ((self::UPDATE_CATEGORY_DATA && $this->categoryImportMode == "MERGE") || ($this->categoryImportMode == "REWRITE")) {
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

    private function createCategoryMappingSinchTables()
    {
        $sinch_categories_mapping = $this->getTableName('sinch_categories_mapping');
        $sinch_categories_mapping_temp = $this->getTableName('sinch_categories_mapping_temp');

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories_mapping_temp");
        $this->_doQuery(
            "CREATE TABLE $sinch_categories_mapping_temp
                (
                    shop_entity_id                INT(11) UNSIGNED NOT NULL,
                    shop_parent_id                INT(11),
                    shop_store_category_id        INT(11),
                    shop_parent_store_category_id INT(11),
                    store_category_id             INT(11),
                    parent_store_category_id      INT(11),
                    category_name                 VARCHAR(255),
                    order_number                  INT(11),
                    products_within_this_category INT(11),

                    UNIQUE KEY shop_entity_id (shop_entity_id),
                    KEY shop_parent_id (shop_parent_id),
                    UNIQUE KEY store_category_id (store_category_id),
                    KEY parent_store_category_id (parent_store_category_id),
                    UNIQUE KEY(shop_entity_id)
                )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );

        $this->_doQuery(
            "CREATE TABLE IF NOT EXISTS $sinch_categories_mapping LIKE $sinch_categories_mapping_temp"
        );
    }

    //TODO: Remove pointless attribute ids passed as args
    private function addCategoryData(
        $_categoryDefault_attribute_set_id,
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
        $catalog_category_entity_text = $this->getTableName('catalog_category_entity_text');

        $ignore = 'IGNORE';
        $onDuplicate = '';
        if (self::UPDATE_CATEGORY_DATA) {
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

        $this->mapSinchCategories($name_attrid, true);

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
        if ($this->categoryImportMode == "REWRITE" || self::UPDATE_CATEGORY_DATA) {
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
            foreach([0, $this->dataHelper->getDefaultStoreId()] as $storeId) {
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
                "INSERT INTO $catalog_category_entity_text
                (attribute_id, store_id, entity_id, value)
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
                "INSERT INTO $catalog_category_entity_text (attribute_id, store_id, entity_id, value)
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
                "INSERT IGNORE INTO $catalog_category_entity_text (attribute_id, store_id, entity_id, value)
                (
                    SELECT :catMetaDescriptionAttr, 0, scm.shop_entity_id, c.MetaDescription
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":catMetaDescriptionAttr" => $this->_categoryMetadescriptionAttrId]
            );

            $this->_doQuery(
                "INSERT IGNORE INTO $catalog_category_entity_text (attribute_id, store_id, entity_id, value)
                (
                    SELECT :catDescriptionAttr, 0, scm.shop_entity_id, c.Description
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )",
                [":catDescriptionAttr" => $this->_categoryDescriptionAttrId]
            );
        }

        if($this->categoryImportMode == 'MERGE') {
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
        $name_attrid,
        $attr_display_mode,
        $attr_url_key,
        $attr_include_in_menu,
        $attr_is_active,
        $image_attrid,
        $is_anchor_attrid
    ){
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
            $name_attrid
        );

        $this->addCategoryData(
            $_categoryDefault_attribute_set_id,
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
                        ($name_attrid,       {$this->dataHelper->getDefaultStoreId()}, $i, '$key'),
                        ($attr_display_mode, {$this->dataHelper->getDefaultStoreId()}, $i, '$key'),
                        ($attr_url_key,      0, $i, '$key')"
            );

            $this->_doQuery(
                "INSERT $catalog_category_entity_int
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($attr_is_active,       0, $i, 1),
                        ($attr_is_active,       {$this->dataHelper->getDefaultStoreId()}, $i, 1),
                        ($attr_include_in_menu, 0, $i, 1),
                        ($attr_include_in_menu, {$this->dataHelper->getDefaultStoreId()}, $i, 1)"
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

    private function parseProductCategories(): void
    {
        $parseFile = $this->dlHelper->getSavePath(Download::FILE_PRODUCT_CATEGORIES);
        if ($this->dlHelper->validateFile(Download::FILE_PRODUCT_CATEGORIES)) {
            $this->_log("Start parse " . Download::FILE_PRODUCT_CATEGORIES);

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName(
                    'product_categories_temp'
                )
            );
            $this->_doQuery(
                "CREATE TABLE " . $this->getTableName('product_categories_temp') . "(
                          store_product_id int(11),
                          store_category_id int(11),
                          key(store_product_id),
                          key(store_category_id)
                )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
            );

            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->getTableName('product_categories_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES "
            );

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('sinch_product_categories')
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('product_categories_temp') . " TO " . $this->getTableName('sinch_product_categories')
            );

            $this->_log("Finish parse " . Download::FILE_PRODUCT_CATEGORIES);
        } else {
            $this->_log("Wrong file " . $parseFile);
        }
    }

    private function parseProducts(): void
    {
        $this->print("--Parse Products 1");
        $productsCsv = $this->dlHelper->getSavePath(Download::FILE_PRODUCTS);

        if (filesize($productsCsv)) {
            $this->_log("Start parse " . Download::FILE_PRODUCTS);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->getTableName('products_temp'));
            $this->_doQuery(
                "CREATE TABLE {$this->getTableName('products_temp')} (
                         sinch_product_id int(11),
                         product_sku varchar(255),
                         product_name varchar(255),
                         sinch_manufacturer_id int(11),
                         main_image_url varchar(255),
                         medium_image_url varchar(255),
                         thumb_image_url varchar(255),
                         specifications text,
                         description text,
                         description_type varchar(50),
                         short_description mediumtext,
                         Title varchar(255),
                         Weight decimal(15,4),
                         family_id int(11),
                         series_id int(11),
                         unspsc int(11),
                         ean_code varchar(32),
                         score int(11),
                         release_date datetime,
                         eol_date datetime,
                         implied_sales_month int(11) NOT NULL DEFAULT 0,
                         implied_sales_year int(11) NOT NULL DEFAULT 0,
                         searches int(11) NOT NULL DEFAULT 0,
                         list_summary_title_1 varchar(255),
                         list_summary_value_1 varchar(255),
                         list_summary_title_2 varchar(255),
                         list_summary_value_2 varchar(255),
                         list_summary_title_3 varchar(255),
                         list_summary_value_3 varchar(255),
                         list_summary_title_4 varchar(255),
                         list_summary_value_4 varchar(255),
                         manufacturer_name varchar(255) default NULL,
                         store_category_id int(11),
                         KEY pt_store_category_product_id (`store_category_id`),
                         KEY pt_product_sku (`product_sku`),
                         KEY pt_sinch_product_id (`sinch_product_id`),
                         KEY pt_sinch_manufacturer_id (`sinch_manufacturer_id`),
                         KEY pt_manufacturer_name (`manufacturer_name`)
                      ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
            );
            $this->print("--Parse Products 2");

            //Products CSV is ID|Sku|Name|BrandID|MainImageURL|ThumbImageURL|Specifications|Description|DescriptionType|MediumImageURL|Title|Weight|ShortDescription|UNSPSC|EANCode|FamilyID|SeriesID|Score|ReleaseDate|EndOfLifeDate|Searches|Feature1|Value1|Feature2|Value2|Feature3|Value3|Feature4|Value4|LastYearSales|LastMonthSales
            // UNSPSC needs specific handling as it's a numeric field where 0 isn't an acceptable default value
            $this->_doQuery(
                "LOAD DATA LOCAL INFILE '{$productsCsv}'
                          INTO TABLE {$this->getTableName('products_temp')}
                          FIELDS TERMINATED BY '{$this->field_terminated_char}'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES
                          (
                            sinch_product_id,
                            product_sku,
                            product_name,
                            sinch_manufacturer_id,
                            main_image_url,
                            thumb_image_url,
                            specifications,
                            description,
                            description_type,
                            medium_image_url,
                            Title,
                            Weight,
                            short_description,
                            @unspsc,
                            ean_code,
                            family_id,
                            series_id,
                            score,
                            release_date,
                            eol_date,
                            searches,
                            list_summary_title_1,
                            list_summary_value_1,
                            list_summary_title_2,
                            list_summary_value_2,
                            list_summary_title_3,
                            list_summary_value_3,
                            list_summary_title_4,
                            list_summary_value_4,
                            implied_sales_year,
                            implied_sales_month
                          )
                          SET unspsc = IF(CHAR_LENGTH(TRIM(@unspsc)) = 0, NULL, @unspsc)"
            );


            $this->_doQuery(
                "UPDATE {$this->getTableName('products_temp')}
                      SET product_name = Title WHERE Title != ''"
            );
            $this->_doQuery(
                "UPDATE {$this->getTableName('products_temp')} pt
                            JOIN {$this->getTableName('sinch_product_categories')} spc
                            SET pt.store_category_id = spc.store_category_id
                            WHERE pt.sinch_product_id = spc.store_product_id"
            );
            $this->_doQuery(
                "UPDATE {$this->getTableName('products_temp')}
                    SET main_image_url = medium_image_url WHERE main_image_url = ''"
            );

            $this->unspscImport->parse();

            $this->print("--Parse Products 3");
            $this->print("--Parse Products 4");

            $this->_doQuery(
                "UPDATE {$this->getTableName('products_temp')} p
                    JOIN {$this->getTableName('sinch_manufacturers')} m
                    ON p.sinch_manufacturer_id = m.sinch_manufacturer_id
                    SET p.manufacturer_name = m.manufacturer_name"
            );

            $this->print("--Parse Products 5");

            if ($this->current_import_status_statistic_id) {
                $prodCount = $this->conn->fetchOne("SELECT COUNT(*) FROM {$this->getTableName('products_temp')}");
                $this->conn->query(
                    "UPDATE {$this->import_status_statistic_table}
                        SET number_of_products = :count
                        WHERE id = :importId",
                    [":count" => $prodCount, ":importId" => $this->current_import_status_statistic_id]
                );
            }

            if ($this->productImportMode == "REWRITE") {
                $catalog_product_entity = $this->getTableName('catalog_product_entity');
                //Allow retrying, as this is particularly likely to deadlock if the site is being used
                $this->retriableQuery("DELETE FROM $catalog_product_entity WHERE type_id = 'simple' AND sinch_product_id IS NOT NULL");
            }

            $this->print("--Parse Products 6");

            $this->addProductsWebsite();
            $this->mapProducts();

            $this->print("--Parse Products 7");
            $this->replaceMagentoProductsMultistore($this->productImportMode == "MERGE");
            $this->print("--Parse Products 8");

            $this->mapProducts(true);

            //$this->addManufacturer_attribute();
            $this->brandImport->apply();

            $this->_doQuery(
                "DROP TABLE IF EXISTS " . $this->getTableName('sinch_products')
            );
            $this->_doQuery(
                "RENAME TABLE " . $this->getTableName('products_temp') . "
                          TO " . $this->getTableName('sinch_products')
            );
            $this->_log("Finish parse " . Download::FILE_PRODUCTS);
        } else {
            $this->_log("Wrong file " . $productsCsv);
        }
    }

    private function retriableQuery($query): void
    {
        while (true) {
            try {
                $this->_doQuery($query);
                return;
            } catch (DeadlockException $_e) {
                $this->print("Sleeping as the previous attempt deadlocked");
                sleep(10);
            }
        }
    }

    private function addProductsWebsite(): void
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
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

    private function mapProducts(bool $mapping_again = false): void
    {
        $sinch_products_mapping_temp = $this->getTableName('sinch_products_mapping_temp');
        $catalog_product_entity = $this->getTableName('catalog_product_entity');
        $products_temp = $this->getTableName('products_temp');

        $this->conn->query(
            "DROP TABLE IF EXISTS $sinch_products_mapping_temp"
        );
        $this->conn->query(
            "CREATE TABLE $sinch_products_mapping_temp (
                  entity_id int(11) unsigned NOT NULL,
                  product_sku varchar(64) NOT NULL,
                  shop_sinch_product_id int(11),
                  sinch_product_id int(11),
                  KEY entity_id (entity_id),
                  KEY sinch_product_id (sinch_product_id),
                  UNIQUE KEY product_sku (product_sku),
                  UNIQUE KEY(entity_id)
            )"
        );

        $catalog_product_entity_source = $this->getTableName('sinch_product_backup');
        //When the import type is merge, we're mapping again, or the backup table is empty, use catalog_product_entity
        if ($this->productImportMode == "MERGE" || $mapping_again || !$this->tableHasData($catalog_product_entity_source)) {
            $catalog_product_entity_source = $catalog_product_entity;
        }

        //Ignore to drop rows which break the unique key constraints silently
        $this->conn->query(
            "INSERT ignore INTO $sinch_products_mapping_temp (entity_id, product_sku, shop_sinch_product_id)
                SELECT entity_id, sku, sinch_product_id
                    FROM $catalog_product_entity_source"
        );

        $this->conn->query(
            "UPDATE $sinch_products_mapping_temp pmt
                INNER JOIN $products_temp pt
                    ON pmt.product_sku = pt.product_sku
                SET
                    pmt.sinch_product_id = pt.sinch_product_id"
        );

        $this->conn->query(
            "UPDATE $catalog_product_entity cpe
                INNER JOIN $sinch_products_mapping_temp pmt
                    ON cpe.entity_id = pmt.entity_id
                SET cpe.sinch_product_id = pmt.sinch_product_id
                WHERE
                    (cpe.sinch_product_id IS NULL
                    AND pmt.sinch_product_id IS NOT NULL)
                    OR cpe.sinch_product_id != pmt.sinch_product_id"
        );

        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');
        $this->conn->query("DROP TABLE IF EXISTS $sinch_products_mapping");
        $this->conn->query("RENAME TABLE $sinch_products_mapping_temp TO $sinch_products_mapping");
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

    private function dropHTMLentities($attribute_id): void
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

    private function valid_char($string): array|string|null
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

    private function addDescriptions(bool $merge): void
    {
        $ignore = $merge ? "IGNORE" : "";
        $onDuplicate = $merge ? "" : "ON DUPLICATE KEY UPDATE value = pt.description";

        // product description (website scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_text')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                pwt.website,
                cpe.entity_id,
                pt.description
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN {$this->getTableName('products_website_temp')} pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('description')]
        );

        // product description (global scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_text')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                0,
                cpe.entity_id,
                pt.description
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('description')]
        );
    }

    private function cleanProductDistributors(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->_doQuery(
                "UPDATE " . $this->getTableName('catalog_product_entity_varchar') . "
                    SET value = ''
                    WHERE attribute_id = " . $this->dataHelper->getProductAttributeId('supplier_' . $i)
            );
        }
    }

    private function addWeight(bool $merge): void
    {
        $ignore = $merge ? "IGNORE" : "";
        $onDuplicate = $merge ? "" : "ON DUPLICATE KEY UPDATE value = pt.Weight";

        // product weight (website scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_decimal')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                pwt.website,
                cpe.entity_id,
                pt.Weight
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN {$this->getTableName('products_website_temp')} pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('weight')]
        );

        // product weight (global scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_decimal')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                0,
                cpe.entity_id,
                pt.Weight
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('weight')]
        );
    }

    private function addShortDescriptions(bool $merge): void
    {
        $ignore = $merge ? "IGNORE" : "";
        $onDuplicate = $merge ? "" : "ON DUPLICATE KEY UPDATE value = pt.short_description";

        // product short description (website scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_text')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                pwt.website,
                cpe.entity_id,
                pt.short_description
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN {$this->getTableName('products_website_temp')} pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('short_description')]
        );
        // product short description (global scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_text')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                0,
                cpe.entity_id,
                pt.short_description
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('short_description')]
        );
    }

    private function addMetaTitle(bool $merge): void
    {
        $configMetaTitle = $this->scopeConfig->getValue(
            'sinchimport/general/meta_title',
            ScopeInterface::SCOPE_STORE);

        if ($configMetaTitle == 1) {
            $ignore = $merge ? "IGNORE" : "";
            $onDuplicate = $merge ? "" : "ON DUPLICATE KEY UPDATE value = pt.Title";

            // Meta title (website scope)
            $this->_doQuery(
                "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_varchar')} (
                    attribute_id,
                    store_id,
                    entity_id,
                    value
                )(
                  SELECT
                    :attributeId,
                    pwt.website,
                    cpe.entity_id,
                    pt.Title
                  FROM {$this->getTableName('catalog_product_entity')} cpe
                  INNER JOIN {$this->getTableName('products_temp')} pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
                  INNER JOIN {$this->getTableName('products_website_temp')} pwt
                    ON cpe.sinch_product_id = pwt.sinch_product_id
                ) $onDuplicate",
                [":attributeId" => $this->dataHelper->getProductAttributeId('meta_title')]
            );

            // Meta title (global scope)
            $this->_doQuery(
                "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_varchar')} (
                    attribute_id,
                    store_id,
                    entity_id,
                    value
                )(
                  SELECT
                    :attributeId,
                    0,
                    cpe.entity_id,
                    pt.Title
                  FROM {$this->getTableName('catalog_product_entity')} cpe
                  INNER JOIN {$this->getTableName('products_temp')} pt
                    ON cpe.sinch_product_id = pt.sinch_product_id
                ) $onDuplicate",
                [":attributeId" => $this->dataHelper->getProductAttributeId('meta_title')]
            );
        } else {
            $this->print("-- Ignore the meta title for product configuration.");
            $this->_logImportInfo("-- Ignore the meta title for product configuration.");
        }
    }


    protected function _logImportInfo(string $logString = '', bool $isError = false): void
    {
        if ($logString) {
            if ($isError) {
                $logString = "[ERROR] " . $logString;
            }
            $this->_sinchLogger->info($logString);
        }
    }

    private function addMetaDescriptions(bool $merge): void
    {
        $ignore = $merge ? "IGNORE" : "";
        $onDuplicate = $merge ? "" : "ON DUPLICATE KEY UPDATE value = pt.short_description";

        // product meta description (website scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_varchar')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                pwt.website,
                cpe.entity_id,
                pt.short_description
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN {$this->getTableName('products_website_temp')} pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('meta_description')]
        );
        // product meta description (global scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_varchar')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                0,
                cpe.entity_id,
                pt.short_description
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('meta_description')]
        );
    }

    private function addSpecification(bool $merge): void
    {
        $ignore = $merge ? "IGNORE" : "";
        $onDuplicate = $merge ? "" : "ON DUPLICATE KEY UPDATE value = pt.specifications";

        // product specification (website scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_text')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                pwt.website,
                cpe.entity_id,
                pt.specifications
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                ON cpe.sinch_product_id = pt.sinch_product_id
              INNER JOIN {$this->getTableName('products_website_temp')} pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('specification')]
        );

        // product specification (global scope)
        $this->_doQuery(
            "INSERT $ignore INTO {$this->getTableName('catalog_product_entity_text')} (
                attribute_id,
                store_id,
                entity_id,
                value
            )(
              SELECT
                :attributeId,
                0,
                cpe.entity_id,
                pt.specifications
              FROM {$this->getTableName('catalog_product_entity')} cpe
              INNER JOIN {$this->getTableName('products_temp')} pt
                  ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate",
            [":attributeId" => $this->dataHelper->getProductAttributeId('specification')]
        );
    }

    private function replaceMagentoProductsMultistore(bool $merge_mode): void
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

        $attr_status = $this->dataHelper->getProductAttributeId('status');
        $attr_name = $this->dataHelper->getProductAttributeId('name');
        $attr_visibility = $this->dataHelper->getProductAttributeId('visibility');
        $attr_tax_class_id = $this->dataHelper->getProductAttributeId('tax_class_id');
        $attr_image = $this->dataHelper->getProductAttributeId('image');
        $attr_small_image = $this->dataHelper->getProductAttributeId('small_image');
        $attr_thumbnail = $this->dataHelper->getProductAttributeId('thumbnail');

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

        // New Products (no existing entity ID)
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

        // "Existing" products (existing entity ID, possibly from backup IDs)
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

        $this->print("--Replace Magento Multistore 4...");

        //Delete int values for non-existent products
        $this->_doQuery(
            "DELETE cpei
            FROM $catalog_product_entity_int cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL"
        );

        $ignore = $merge_mode ? "IGNORE" : "";
        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = 1";

        $this->_doQuery(
            "INSERT $ignore INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_status,
                pwt.website,
                cpe.entity_id,
                1
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 5...");
        // We don't use the mapping table as it won't contain products created this import (they won't be mapped until after this function completes)
        // It seems that our replacement just using sinch_product_id on cpe runs in about the same time (or faster) anyway

        // set status = 1 for all stores
        $this->_doQuery(
            "INSERT $ignore INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value) (
                SELECT
                    $attr_status,
                    0,
                    cpe.entity_id,
                    1
                FROM $catalog_product_entity cpe
                WHERE cpe.sinch_product_id IS NOT NULL
            ) $onDuplicate"
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

        if (!$merge_mode) {
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
                "INSERT INTO $catalog_category_product_for_delete_temp (category_id, product_id, store_product_id)
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
        } else { //Merge mode, originally from replaceMagentoProductsMultistoreMERGE
            //TODO: This potentially doesn't need the intermediary table (dependent on the worst case scenario of the query)
            $sinch_products = $this->getTableName('sinch_products');
            $sinch_products_delete = $this->getTableName('sinch_products_delete');

            $this->_doQuery("DROP TABLE IF EXISTS $sinch_products_delete");
            $this->_doQuery(
                "CREATE TABLE $sinch_products_delete
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
            $this->_doQuery("DELETE cpe FROM $catalog_product_entity cpe INNER JOIN $sinch_products_delete spd USING(entity_id)");
            $this->_doQuery("DROP TABLE IF EXISTS $sinch_products_delete");
        }
        $this->print("--Replace Magento Multistore 16 (add multi categories)...");
        $sinch_product_categories = $this->getTableName('sinch_product_categories');

        $this->_doQuery("INSERT INTO $catalog_category_product (category_id, product_id) (
            SELECT scm.shop_entity_id, cpe.entity_id
                FROM $sinch_product_categories spc
            INNER JOIN $catalog_product_entity cpe
                ON spc.store_product_id = cpe.sinch_product_id
            INNER JOIN $sinch_categories_mapping scm
                ON spc.store_category_id = scm.store_category_id
            LEFT JOIN $catalog_category_entity cce
                ON scm.shop_entity_id = cce.entity_id
            WHERE cce.entity_id IS NOT NULL
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

        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = pt.product_name";

        // Product name (website scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
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
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 21...");

        // Product name (global scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                0,
                cpe.entity_id,
                pt.product_name
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 22...");

        $this->dropHTMLentities($this->dataHelper->getProductAttributeId('name'));
        $this->addDescriptions($merge_mode);
        $this->cleanProductDistributors();
        $this->addWeight($merge_mode);

        //Formerly addPdfUrl
        if ($this->multimediaImport->haveRequiredFiles()) {
            $this->print("Parsing Multimedia...");
            $this->multimediaImport->parse();
        } else {
            $this->print("Missing required files for multimedia import section, skipping");
        }

        $this->print("Adding short descriptions...");
        $this->addShortDescriptions($merge_mode);
        $this->print("StockPrice apply distributors...");
        $this->stockPriceImport->applyDistributors();

        $this->print("Adding meta title...");
        $this->addMetaTitle($merge_mode);
        $this->print("Adding meta descriptions...");
        $this->addMetaDescriptions($merge_mode);
        //Replaced addEAN
        $this->print("Adding EAN codes...");
        $this->eanImport->parse();
        $this->print("Adding specifications...");
        $this->addSpecification($merge_mode);

        //TODO: Run brands section apply here
        //$this->addManufacturers();
        $this->print("Applying Manufacturers...");
        $this->brandImport->apply();

        $this->print("--Replace Magento Multistore 23...");

        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = 4";

        //Make product visible to catalog and search (website scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                pwt.website,
                cpe.entity_id,
                4
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 24...");

        //Make product visible to catalog and search (global scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                0,
                cpe.entity_id,
                4
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            ) $onDuplicate"
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

        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = 2";

        //Adding tax class "Taxable Goods" (website scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                pwt.website,
                cpe.entity_id,
                2
            FROM $catalog_product_entity cpe
            JOIN $products_website_temp pwt
                ON cpe.sinch_product_id = pwt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 28...");

        //Adding tax class "Taxable Goods" (global scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_int (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                0,
                cpe.entity_id,
                2
            FROM $catalog_product_entity cpe
            WHERE cpe.sinch_product_id IS NOT NULL
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 29...");

        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = pt.main_image_url";
        // Image Url (website scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                store.store_id,
                cpe.entity_id,
                pt.main_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 30...");

        // Image Url (global scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                0,
                cpe.entity_id,
                pt.main_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 31...");

        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = pt.medium_image_url";
        // small_image (website scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                store.store_id,
                cpe.entity_id,
                pt.medium_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 32...");

        // small_image (global scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                0,
                cpe.entity_id,
                pt.medium_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 33...");

        $onDuplicate = $merge_mode ? "" : "ON DUPLICATE KEY UPDATE value = pt.thumb_image_url";
        // thumbnail (website scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                store.store_id,
                cpe.entity_id,
                pt.thumb_image_url
            FROM $catalog_product_entity cpe
            JOIN $core_store store
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 34...");

        // thumbnail (global scope)
        $this->retriableQuery(
            "INSERT $ignore INTO $catalog_product_entity_varchar (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                0,
                cpe.entity_id,
                pt.thumb_image_url
            FROM $catalog_product_entity cpe
            JOIN $products_temp pt
                ON cpe.sinch_product_id = pt.sinch_product_id
            ) $onDuplicate"
        );

        $this->print("--Replace Magento Multistore 35...");
    }

    private function parseProductsPicturesGallery(): void
    {
        $parseFile = $this->dlHelper->getSavePath(Download::FILE_PRODUCTS_GALLERY_PICTURES);
        if (filesize($parseFile)) {
            $this->_log(
                "Start parse " . Download::FILE_PRODUCTS_GALLERY_PICTURES
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
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

            $this->_log("Finish parse" . Download::FILE_PRODUCTS_GALLERY_PICTURES);
        } else {
            $this->_log("Wrong file" . $parseFile);
        }
    }

    /**
     * @throws LocalizedException
     */
    public function startCronStockPriceImport(): void
    {
        $this->_log("Start stock price import from cron");

        $this->import_run_type = 'CRON';
        $this->runStockPriceImport();

        $this->_log("Finish stock price import from cron");
    }

    public function runStockPriceImport(): void
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

        if ($this->canImport() && $this->isFullImportHaveBeenRun()) {
            try {
                $current_vhost = $this->scopeConfig->getValue(
                    'web/unsecure/base_url',
                    ScopeInterface::SCOPE_STORE
                );
                $this->_doQuery("SELECT GET_LOCK('sinchimport_{$current_vhost}', 30)");

                $this->addImportStatus('Stock Price Start Import');
                //Once we hold the import lock, check/await indexer completion
                $this->print("Making sure no indexers are currently running");
                if (!$this->sitcIndexMgmt->ensureIndexersNotRunning()) {
                    $this->print("There are indexers currently running, abandoning import");
                    $this->_setErrorMessage("There are indexers currently running, abandoning import");
                    $this->setImportResult('Abandoned');
                    throw new LocalizedException(__("There are indexers currently running, abandoning import"));
                }
                $this->addImportStatus('Stock Price Start Import', true);

                $this->print("========IMPORTING STOCK AND PRICE========");

                $this->addImportStatus('Stock Price Download Files');
                $requiredFiles = [
                    Download::FILE_STOCK_AND_PRICES,
                    Download::FILE_DISTRIBUTORS,
                    Download::FILE_DISTRIBUTORS_STOCK
                ];
                //Files we can live without (we want them but it doesn't seriously affect us if its missing)
                $optionalFiles = [
                    Download::FILE_ACCOUNT_GROUPS,
                    Download::FILE_ACCOUNT_GROUP_CATEGORIES,
                    Download::FILE_ACCOUNT_GROUP_PRICE,
                ];

                $this->downloadFiles($requiredFiles, $optionalFiles);
                $this->addImportStatus('Stock Price Download Files', true);

                $this->addImportStatus('Parse Stock And Prices');
                //Replaces parseStockAndPrices
                $this->stockPriceImport->parse();
                $this->addImportStatus('Parse Stock and Prices', true);
                $this->addImportStatus('Apply Stock and Prices');
                $this->stockPriceImport->apply();
                $this->addImportStatus('Apply Stock and Prices', true);

                if ($this->customerGroupPrice->haveRequiredFiles()) {
                    $this->addImportStatus('Apply Account Group Price');
                    $this->customerGroupPrice->parse();
                    $this->addImportStatus('Apply Account Group Price', true);
                } else {
                    $this->print("Missing required files for account group price section, or downloaded files failed validation, skipping");
                }

                //Allow the CC category visibility import section to be skipped
                $ccCategoryDisable = $this->scopeConfig->getValue(
                    'sinchimport/category_visibility/disable_import',
                    ScopeInterface::SCOPE_STORE
                );

                if (!$ccCategoryDisable) {
                    if ($this->customerGroupCatsImport->haveRequiredFiles()) {
                        $this->addImportStatus('Parse Account Group Categories');
                        $this->customerGroupCatsImport->parse();
                        $this->addImportStatus('Parse Account Group Categories', true);
                    } else {
                        $this->print("Missing required files for account group categories section, or downloaded files failed validation, skipping");
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
                    if ($this->customCatalogImport->haveRequiredFiles()) {
                        $this->addImportStatus('Processing Custom Catalog restrictions');
                        $this->customCatalogImport->parse();
                        $this->addImportStatus('Processing Custom Catalog restrictions', true);
                    } else {
                        $this->print("Missing required files for custom catalog section, or downloaded files failed validation, skipping");
                    }
                } else {
                    $this->print("Skipping custom catalog restrictions as 'sinchimport/product_visibility/disable_import' is enabled");
                }

                try {
                    $this->addImportStatus('Post import hooks');
                    $this->_eventManager->dispatch(
                        'sinchimport_post_import',
                        [
                            'import_type' => 'PRICE STOCK'
                        ]
                    );
                    $this->addImportStatus('Post import hooks', true);
                } catch (Exception $e) {
                    $this->addImportStatus('Post import hooks', true);
                    $this->print("Caught exception while running post import hooks: " . $e->getMessage());
                }

                $this->addImportStatus('Clean cache');
                $this->sitcIndexMgmt->clearCaches();
                $this->addImportStatus('Clean cache', true);

                try {
                    $this->addImportStatus('Import completion hooks');
                    $this->_eventManager->dispatch(
                        'sinchimport_import_complete_post_index',
                        ['import_type' => 'PRICE STOCK']
                    );
                    $this->addImportStatus('Import completion hooks', true);
                } catch (Exception $e) {
                    $this->addImportStatus('Import completion hooks', true);
                    $this->print("Caught exception while running import completion hooks: " . $e->getMessage());
                }

                $this->addImportStatus('Finish Import', true);
                $this->print("========>FINISH STOCK & PRICE SINCH IMPORT");

                $this->_doQuery("SELECT RELEASE_LOCK('sinchimport_{$current_vhost}')");
            } catch (Exception $e) {
                $this->_setErrorMessage($e);
            }
        } else {
            if (!$this->canImport()) {
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

    public function runReindexUrlRewrite(): void
    {
        try {
            $this->print("========REINDEX CATALOG URL REWRITE========");

            $this->print("Start indexing product URL rewrites...");
            $this->sitcIndexMgmt->reindexProductUrls();
            $this->print("Finish indexing product URL rewrites...");

            $this->print("Start indexing category URL rewrites...");
            $this->helperUrl->generateCategoryUrl();
            $this->print("Finish indexing category URL rewrites...");

            $this->print("========>FINISH REINDEX CATALOG URL REWRITE...");
            $this->_doQuery(
                "INSERT INTO $this->import_status_table (message, finished)
                    VALUES('Indexing data separately', 1) ON DUPLICATE KEY UPDATE finished = 1"
            );
        } catch (Exception $e) {
            $this->_setErrorMessage($e);
        }
    }

    /**
     * load Gallery array from XML
     */
    public function loadGalleryPhotos($entity_id): static
    {
        $sinch_product_id = $this->getSinchProductIdByEntity($entity_id);
        if (!$sinch_product_id) {
            return $this;
        }
        $sinch_products_pictures_gallery = $this->getTableName('sinch_products_pictures_gallery');
        $res = $this->_doQuery(
            "SELECT COUNT(*) AS cnt
                FROM $sinch_products_pictures_gallery
                WHERE sinch_product_id = :sinchProductId",
            [':sinchProductId' => $sinch_product_id],
            true
        )->fetch();

        if (!$res || !$res['cnt']) {
            return $this;
        }

        $photos = $this->_doQuery(
            "SELECT image_url as Pic, thumb_image_url as ThumbPic
                FROM $sinch_products_pictures_gallery
                WHERE sinch_product_id = :sinchProductId",
            [':sinchProductId' => $sinch_product_id],
            true
        )->fetchAll();

        foreach ($photos as $photo) {
            $picHeight = 500;
            $picWidth = 500;
            $thumbUrl = (string)$photo["ThumbPic"];
            $picUrl = (string)$photo["Pic"];

            $this->galleryPhotos[] = [
                'height' => $picHeight,
                'width' => $picWidth,
                'thumb' => $thumbUrl,
                'pic' => $picUrl
            ];
        }

        return $this;
    }

    private function getSinchProductIdByEntity($entity_id): ?int
    {
        $sinch_products_mapping = $this->getTableName('sinch_products_mapping');
        return $this->conn->fetchOne(
            "SELECT sinch_product_id FROM $sinch_products_mapping WHERE entity_id = :entityId",
            [':entityId' => $entity_id],
        );
    }

    public function getGalleryPhotos(): array
    {
        return $this->galleryPhotos;
    }

    public function getImportStatusHistory(): array
    {
        $res = $this->_doQuery(
            "SELECT COUNT(*) as cnt FROM " . $this->import_status_statistic_table
        )->fetch();
        $cnt = $res['cnt'];

        $StatusHistory_arr = [];
        if ($cnt > 0) {
            $offset = (($cnt > 7) ? ($cnt - 7) : 0);
            $count = $cnt;

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
                    ORDER BY start_import LIMIT $offset, $count",
                [],
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
            [], true
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
