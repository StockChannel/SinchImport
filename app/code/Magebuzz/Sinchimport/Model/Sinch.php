<?php
/**
 * @copyright Copyright (c) 2016 www.magebuzz.com
 */

namespace Magebuzz\Sinchimport\Model;

ini_set('memory_limit', '512M');
require_once __DIR__ . '/Config.php';

class Sinch  extends \Magento\Framework\Model\AbstractModel
{
    var
        $connection,
        $varDir,
        $shellDir,
        $files,
        $attributes,
        $db,
        $lang_id,
        $debug_mode = 1;
    public $php_run_string;
    public $php_run_strings;
    public $price_breaks_filter;
    private $productDescriptionList = [];
    private $specifications;
    private $productDescription;
    private $fullProductDescription;
    private $lowPicUrl;
    private $highPicUrl;
    private $errorMessage;
    private $galleryPhotos = [];
    private $productName;
    private $relatedProducts = [];
    private $errorSystemMessage;
    private $sinchProductId;
    private $_productEntityTypeId = 0;
    private $defaultAttributeSetId = 0;
    private $field_terminated_char;
    private $import_status_table;
    private $import_status_statistic_table;
    private $current_import_status_statistic_id;
    private $import_log_table;
    private $_attributeId;
    private $_categoryEntityTypeId;
    private $_categoryDefault_attribute_set_id;
    private $_rootCat;
    private $import_run_type = 'MANUAL';
    private $_ignore_category_features = false;
    private $_ignore_product_features = false;
    private $_ignore_product_related = false;
    private $_ignore_product_categories = false;
    private $_ignore_product_contracts = false;
    private $_ignore_price_rules = false;
    private $product_file_format = "NEW";
    private $_ignore_restricted_values = false;
    private $_categoryMetaTitleAttrId;
    private $_categoryMetadescriptionAttrId;
    private $_categoryDescriptionAttrId;
    private $_dataConf;
    private $_deploymentData;
    private $imType;

    /**
     * Filesystem Directory List
     *
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @var State
     */
    protected $_state;

    /**
     * Store manager
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    protected $scopeConfig;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_urlBuilder;

    /**
     * Logging instance
     * @var \Magebuzz\Sinchimport\Logger\Logger
     */
    protected $_sinchLogger;

    protected $_resourceConnection;

    protected $_connection;

    /**
     * @var \Magento\Indexer\Model\Processor
     */
    protected $_indexProcessor;

    /**
     * @var \Magento\Framework\App\Cache\Frontend\Pool
     */
    protected $_cacheFrontendPool;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $_eventManager;

    /**
     * @var \Magento\Framework\Model\ResourceModel\Iterator
     */
    protected $_resourceIterator;

    /**
     * Product collection factory
     *
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $_productCollectionFactory;

    /**
     * Product factory
     *
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $_productFactory;

    /**
     * Product url factory
     *
     * @var \Magebuzz\Sinchimport\Model\Product\UrlFactory
     */
    protected $_productUrlFactory;

    /**
     * @var \Magento\Framework\App\DeploymentConfig
     */
    private $_deploymentConfig;

    /**
     * CMS page cache tag
     */
    const CACHE_TAG = 'sinchimport_sinch';

    /**
     * @var string
     */
    protected $_cacheTag = 'sinchimport_sinch';

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'sinchimport_sinch';

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magebuzz\Sinchimport\Logger\Logger $sinchLogger
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\App\State $state,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magebuzz\Sinchimport\Logger\Logger $sinchLogger,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Indexer\Model\Processor $indexProcessor,
        \Magento\Framework\App\Cache\Frontend\Pool $cacheFrontendPool,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\DeploymentConfig $deploymentConfig,
        \Magento\Framework\Model\ResourceModel\Iterator $resourceIterator,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magebuzz\Sinchimport\Model\Product\UrlFactory $productUrlFactory,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = [])
    {
        $this->_state = $state;
        $this->directoryList = $directoryList;
        $this->_storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->_urlBuilder = $urlBuilder;
        $this->_sinchLogger = $sinchLogger;
        $this->_resourceConnection = $resourceConnection;
        $this->_indexProcessor = $indexProcessor;
        $this->_cacheFrontendPool = $cacheFrontendPool;
        $this->_eventManager = $eventManager;
        $this->_deploymentConfig = $deploymentConfig;
        $this->_resourceIterator = $resourceIterator;
        $this->_productFactory = $productFactory;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->_productUrlFactory = $productUrlFactory;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_connection= $this->_resourceConnection->getConnection();

        $this->import_status_table = $this->_getTableName('sinch_import_status');
        $this->import_status_statistic_table = $this->_getTableName('sinch_import_status_statistic');
        $this->import_log_table = $this->_getTableName('sinch_import_log');

        $this->php_run_string = PHP_RUN_STRING;
        $this->php_run_strings = PHP_RUN_STRINGS;

        $this->price_breaks_filter = PRICE_BREAKS;

        $this->varDir = $this->directoryList->getPath(\Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR) . '/';

        $this->createTemporaryImportDerictory();

        $this->files = array(
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
            FILE_PRODUCT_CONTRACTS
        );

        $this->_dataConf = $this->scopeConfig->getValue(
            'sinchimport/sinch_ftp',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $this->_deploymentData = $this->_deploymentConfig->getConfigData();

        $this->field_terminated_char = DEFAULT_FILE_TERMINATED_CHAR;
    }

    public function startCronFullImport()
    {
        $this->_logImportInfo("Start full import from cron");

        $this->import_run_type = 'CRON';
        $this->runSinchImport();

        $this->_logImportInfo("Finish full import from cron");
    }

    public function startCronStockPriceImport()
    {
        $this->_logImportInfo("Start stock price import from cron");

        $this->import_run_type = 'CRON';
        $this->runStockPriceImport();

        $this->_logImportInfo("Finish stock price import from cron");
    }

    public function runSinchImport()
    {
        $this->_categoryMetaTitleAttrId = $this->_getCategoryAttributeId('meta_title');
        $this->_categoryMetadescriptionAttrId = $this->_getCategoryAttributeId('meta_description');
        $this->_categoryDescriptionAttrId = $this->_getCategoryAttributeId('description');

        $safe_mode_set = ini_get('safe_mode');
        $this->initImportStatuses('FULL');

        if ($safe_mode_set) {
            $this->_logImportInfo('safe_mode is enable. import stoped.');
            $this->_setErrorMessage('Safe_mode is enabled. Please check the documentation on how to fix this. Import stopped.');
            exit;
        }

        $store_proc = $this->checkStoreProcedureExist();
        if (!$store_proc) {
            $this->_logImportInfo('store prcedure "' . $this->_getTableName('sinch_filter_products') . '" is absent in this database. import stoped.');
            $this->_setErrorMessage('Stored procedure "' . $this->_getTableName('sinch_filter_products') . '" is absent in this database. Import stopped.');
            exit;
        }

        $file_privileg = $this->checkDbPrivileges();
        if (!$file_privileg) {
            $this->_logImportInfo("Loaddata option not set - please check the documentation on how to fix this. You dan't have privileges for LOAD DATA.");
            $this->_setErrorMessage("Loaddata option not set - please check the documentation on how to fix this. Import stopped.");
            exit;
        }

        $local_infile = $this->checkLocalInFile();
        if (!$local_infile) {
            $this->_logImportInfo("Loaddata option not set - please check the documentation on how to fix this. Add this string to  'set-variable=local-infile=0' in '/etc/my.cnf'");
            $this->_setErrorMessage("Loaddata option not set - please check the documentation on how to fix this. Import stopped.");
            exit;
        }

        if ($this->isImportNotRun()) {
            try {
                $imType = $this->_dataConf['replace_category'];

                $q = "SELECT GET_LOCK('sinchimport', 30)";
                $quer = $this->_doQuery($q);
                $this->addImportStatus('Start Import');

                echo("\n========IMPORTING DATA IN $imType MODE========\n");

                echo "\nUpload Files...\n";
                $this->uploadFiles();
                $this->addImportStatus('Upload Files');

                echo "\nParse Category Types...";
                $this->parseCategoryTypes();

                echo "\nParse Categories...";
                $coincidence = $this->parseCategories();
                $this->addImportStatus('Parse Categories');

                echo "\nParse Category Features...";
                $this->parseCategoryFeatures();
                $this->addImportStatus('Parse Category Features');

                echo "\nParse Distributors...";
                $this->parseDistributors();
                if ($this->product_file_format == "NEW") {
                    $this->parseDistributorsStockAndPrice();
                    $this->parseProductContracts();
                }
                $this->addImportStatus('Parse Distributors');

                echo "\nParse EAN Codes...";
                $this->parseEANCodes();
                $this->addImportStatus('Parse EAN Codes');
                echo "\nParse Manufacturers...";
                $this->parseManufacturers();
                $this->addImportStatus('Parse Manufacturers');

                echo "\nParse Related Products...";
                $this->parseRelatedProducts();
                $this->addImportStatus('Parse Related Products');
                echo "\nParse Product Features...";
                $this->parseProductFeatures();
                $this->addImportStatus('Parse Product Features');

                echo "\nParse Product Categories...";
                $this->parseProductCategories();

                echo "\nParse Products...";
                $this->parseProducts($coincidence);
                $this->addImportStatus('Parse Products');

                echo "\nParse Pictures Gallery...";
                $this->parseProductsPicturesGallery();
                $this->addImportStatus('Parse Pictures Gallery');
                echo "\nParse Restricted Values...";
                $this->parseRestrictedValues();
                $this->addImportStatus('Parse Restricted Values');

                echo "\nParse Stock And Prices...";
                $this->parseStockAndPrices();
                $this->addImportStatus('Parse Stock And Prices');

                echo "\nApply Customer Group Price...";

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

                $this->_logImportInfo("Start drop feature result tables");
                echo "\nStart dropping feature result tables...";
                $this->_dropFeatureResultTables();
                $this->_logImportInfo("Finish drop feature result tables");
                $this->addImportStatus('Generate category filters');
                echo "\nFinish dropping feature result tables...";

                $this->_logImportInfo("Start indexing data");
                echo "\nStart indexing data...";
                $this->_cleanCateoryProductFlatTable();
                $this->runIndexer();
                echo "\nStart indexing catalog url rewrites...";
                $this->_reindexProductUrlKey();
                echo "\nFinish indexing catalog url rewrites...";
                $this->_logImportInfo("Finish indexing data...");
                $this->addImportStatus('Indexing data', 1);
                echo "\nFinish indexing data...";

                $this->_logImportInfo("Start cleanin Sinch cache...");
                echo "\nStart cleanin Sinch cache...";
                $this->runCleanCache();
                $this->_logImportInfo("Finish cleanin Sinch cache...");
                echo "\nFinish cleanin Sinch cache...";

                $this->addImportStatus('Finish import', 1);
                $this->_logImportInfo("Finish Sinch Import");
                echo "\n\n========>Finish Sinch Import...\n";

                $q = "SELECT RELEASE_LOCK('sinchimport')";
                $quer = $this->_doQuery($q);
            } catch (Exception $e) {
                $this->_setErrorMessage($e);
            }
        } else {
            $this->_logImportInfo("Sinchimport already run");
            echo "\nSinchimport already run...";
        }
    }

    /**
     * Create the import directory Hierarchy
     * @return false if directory already exists
     */
    public function createTemporaryImportDerictory()
    {
        $dirArray = explode('/', $this->varDir);
        end($dirArray);

        if (prev($dirArray) == 'magebuzz') {
            return false;
        }

        $this->varDir = $this->varDir . 'magebuzz/sinchimport/';
        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0777, true);
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

        if (!isset($this->_attributeId[$typeCode]) OR !is_array($this->_attributeId[$typeCode])) {
            $sql = "
                    SELECT attribute_id, attribute_code
                    FROM " . $this->_getTableName('eav_attribute') . "
                    WHERE entity_type_id = '" . $typeId . "'
                   ";

            $result = $this->_doQuery($sql)->fetchAll();

            if ($result) {
                foreach($result AS $resultItem) {
                    $this->_attributeId[$typeCode][$resultItem['attribute_code']] = $resultItem['attribute_id'];
                }
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
        $sql = "
            SELECT entity_type_id
            FROM " . $this->_getTableName('eav_entity_type') . "
            WHERE entity_type_code = '" . $code . "'
            LIMIT 1
        ";
        $result = $this->_doQuery($sql)->fetch();

        if ($result) {
            return $result['entity_type_id'];
        }

        return false;
    }

    public function initImportStatuses($type)
    {
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->import_status_table);
        $this->_doQuery("CREATE TABLE " . $this->import_status_table . "(
                        id int(11) NOT NULL auto_increment PRIMARY KEY,
                        message varchar(50),
                        finished int(1) default 0
                      )"
        );
        $this->_doQuery("INSERT INTO " . $this->import_status_statistic_table . " (
                        start_import,
                        finish_import,
                        import_type,
                        global_status_import,
                        import_run_type,
                        error_report_message)
                      VALUES(
                        now(),
                        NULL,
                        '$type',
                        'Run',
                        '" . $this->import_run_type . "',
                        ''
                      )
                    ");
        $q = "SELECT MAX(id) AS id FROM " . $this->import_status_statistic_table;

        $result = $this->_doQuery($q)->fetch();
        $this->current_import_status_statistic_id = !empty($result['id']) ? $result['id'] : 0;
        $this->_doQuery("UPDATE " . $this->import_status_statistic_table . "
            SET global_status_import='Failed'
            WHERE global_status_import='Run' AND id!=" . $this->current_import_status_statistic_id);
    }

    public function checkStoreProcedureExist()
    {
        $q = 'SHOW PROCEDURE STATUS LIKE "' . $this->_getTableName('sinch_filter_products') . '"';
        $query = $this->_doQuery($q);

        while ($result = $query->fetch()){
            if (($result['Name'] == $this->_getTableName('sinch_filter_products')) && ($result['Db'] == $this->_deploymentData['db']['connection']['default']['dbname'])) {
                return true;
            }
        }

        return false;
    }

    public function checkDbPrivileges()
    {
        return true;

        $q = 'SHOW PRIVILEGES';

        $result = $this->_doQuery($q)->fetch();

        if ($result['Privilege'] == 'File' && $result['Context'] == 'File access on server') {
            return true;
        }

        return false;
    }

    public function checkLocalInFile()
    {
        $q = 'SHOW VARIABLES LIKE "local_infile"';

        $result = $this->_doQuery($q)->fetch();

        if ($result['Variable_name'] == 'local_infile' && $result['Value'] == "ON") {
            return true;
        }

        return false;
    }

    public function isImportNotRun()
    {
        $q = "SELECT IS_FREE_LOCK('sinchimport') as getlock";
        $result = $this->_doQuery($q)->fetch();
        return $result['getlock'];
    }

    public function addImportStatus($message, $finished = 0)
    {
        $q = "INSERT INTO " . $this->import_status_table . "
            (message, finished)
            VALUES('" . $message . "', $finished)";
        $this->_doQuery($q);
        $this->_doQuery("UPDATE " . $this->import_status_statistic_table . "
                      SET detail_status_import='" . $message . "'
                      WHERE id=" . $this->current_import_status_statistic_id);
        if ($finished == 1) {
            $this->_doQuery("
                UPDATE " . $this->import_status_statistic_table . "
                SET
                    global_status_import='Successful',
                    finish_import=now()
                WHERE
                    error_report_message='' and
                    id=" . $this->current_import_status_statistic_id
            );
        }
    }

    public function uploadFiles()
    {
        $this->_logImportInfo("Start upload files");

        $username = $this->_dataConf['username'];
        $passw = $this->_dataConf['password'];
        $server = $this->_dataConf['ftp_server'];

        if (!$username || !$passw) {
            $this->_logImportInfo('ftp login or password dosent defined');
            $this->_setErrorMessage('FTP login or password has not been defined. Import stopped.');
            exit;

        }
        $file_url_and_dir = $this->replPh(FILE_URL_AND_DIR, array(
                'server' => $server,
                'login' => $username,
                'password' => $passw
            )
        );
        foreach ($this->files as $file) {
            $this->_logImportInfo("Copy " . $file_url_and_dir . $file . " to  " . $this->varDir . $file);
            if (strstr($file_url_and_dir, 'ftp://')) {
                preg_match("/ftp:\/\/(.*?):(.*?)@(.*?)(\/.*)/i", $file_url_and_dir, $match);
                if ($conn = ftp_connect($match[3])) {
                    if (!ftp_login($conn, $username, $passw)) {
                        $this->_setErrorMessage('Incorrect username or password for the Stock In The Channel server. Import stopped.');
                        exit;
                    }
                } else {
                    $this->_setErrorMessage('FTP connection failed. Unable to connect to the Stock In The Channel server');
                    exit;
                }
                if (!$this->wget($file_url_and_dir . $file, $this->varDir . $file, 'system')) {
                    $this->_logImportInfo("wget Can't copy " . $file . ", will use old one");
                    echo "copy Can't copy " . $file_url_and_dir . $file . " to  " . $this->varDir . $file . ", will use old one<br>";
                }
            } else {
                if (!copy($file_url_and_dir . $file, $this->varDir . $file)) {
                    $this->_logImportInfo("copy Can't copy " . $file . ", will use old one");
                    echo "copy Can't copy " . $file_url_and_dir . $file . " to  " . $this->varDir . $file . " will use old one<br>";
                }
            }
            exec("chmod a+rw " . $this->varDir . $file);
            if (!filesize($this->varDir . $file)) {
                if ($file != FILE_CATEGORIES_FEATURES && $file != FILE_PRODUCT_FEATURES && $file != FILE_RELATED_PRODUCTS && $file != FILE_RESTRICTED_VALUES && $file != FILE_PRODUCT_CATEGORIES && $file != FILE_CATEGORY_TYPES && $file != FILE_DISTRIBUTORS_STOCK_AND_PRICES && $file != FILE_PRODUCT_CONTRACTS && $file != FILE_PRICE_RULES) {
                    $this->_logImportInfo("Can't copy " . $file_url_and_dir . $file . ". file $this->varDir.$file is emty");
                    $this->_setErrorMessage("Can't copy " . $file_url_and_dir . $file . ". file " . $this->varDir . $file . " is emty");
                    $this->addImportStatus('Sinch import stoped. Import file(s) empty', 1);

                    exit;
                } else {
                    if ($file == FILE_CATEGORIES_FEATURES) {
                        $this->_logImportInfo("Can't copy " . FILE_CATEGORIES_FEATURES . " file ignored");
                        $this->_ignore_category_features = true;
                    } elseif ($file == FILE_PRODUCT_FEATURES) {
                        $this->_logImportInfo("Can't copy " . FILE_PRODUCT_FEATURES . " file ignored");
                        $this->_ignore_product_features = true;
                    } elseif ($file == FILE_RELATED_PRODUCTS) {
                        $this->_logImportInfo("Can't copy " . FILE_RELATED_PRODUCTS . " file ignored");
                        $this->_ignore_product_related = true;
                    } elseif ($file == FILE_RESTRICTED_VALUES) {
                        $this->_logImportInfo("Can't copy " . FILE_RESTRICTED_VALUES . " file ignored");
                        $this->_ignore_restricted_values = true;
                    } elseif ($file == FILE_PRODUCT_CATEGORIES) {
                        $this->_logImportInfo("Can't copy " . FILE_PRODUCT_CATEGORIES . " file ignored");
                        $this->_ignore_product_categories = true;
                        $this->product_file_format = "OLD";
                    } elseif ($file == FILE_CATEGORY_TYPES) {
                        $this->_logImportInfo("Can't copy " . FILE_CATEGORY_TYPES . " file ignored");
                        $this->_ignore_category_types = true;
                    } elseif ($file == FILE_DISTRIBUTORS_STOCK_AND_PRICES) {
                        $this->_logImportInfo("Can't copy " . FILE_DISTRIBUTORS_STOCK_AND_PRICES . " file ignored");
                        $this->_ignore_category_types = true;
                    } elseif ($file == FILE_PRODUCT_CONTRACTS) {
                        $this->_logImportInfo("Can't copy " . FILE_PRODUCT_CONTRACTS . " file ignored");
                        $this->_ignore_product_contracts = true;
                    } elseif ($file == FILE_PRICE_RULES) {
                        $this->_logImportInfo("Can't copy " . FILE_PRICE_RULES . " file ignored");
                        $this->_ignore_price_rules = true;
                    }

                }
            }
        }
        if (file_exists($file_url_and_dir . FILE_PRODUCT_CATEGORIES)) {
            $this->product_file_format = "NEW";
            $this->_logImportInfo("File " . $file_url_and_dir . FILE_PRODUCT_CATEGORIES . " exist. Will used parser for NEW format product.csv");
        } else {
            $this->product_file_format = "OLD";
            $this->_logImportInfo("File " . $file_url_and_dir . FILE_PRODUCT_CATEGORIES . " dosen't exist. Will used parser for OLD format product.csv");
        }
        $this->_logImportInfo("Finish upload files");
    }

    private function replPh($content, $hash)
    {
        if ($hash) {
            foreach ($hash as $key => $val) {
                if ($key == "category_name") {
                    if (strlen($val) > 25) {
                        $val = substr($val, 0, 24) . "...";
                    }
                }
                $content = preg_replace("/%%%$key%%%/", $val, $content);
            }
        }
        return $content;
    }

    public function wget()
    {
        $got = func_num_args();
        $url = $file = $flag = false;

        if ($got < 1) {
            return false;
        } elseif ($got == 1) {
            $url = func_get_arg(0);
        } elseif ($got == 2) {
            $url = func_get_arg(0);
            $file = func_get_arg(1);
        } elseif ($got == 3) {
            $url = func_get_arg(0);
            $file = func_get_arg(1);
            $flag = func_get_arg(2);
        }

        if ($flag == 'copy') {
            if (copy($url, $file)) {
                return true;
            } else {
                return false;
            }
        } elseif ($flag == 'system') {
            exec("wget -O$file $url");
            return true;
        } else {
            $c = curl_init($url);
            curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($c, CURLOPT_HEADER, array("Accept-Encoding: gzip"));
            if (!$file) {
                $page = curl_exec($c);
                curl_close($c);
                return $page;
            } else {
                $FH = fopen($file, "wb");
                fwrite($FH, curl_exec($c));
                fclose($FH);
                curl_close($c);
                return true;
            }
        }
    }

    public function parseCategoryTypes()
    {
        $parseFile = $this->varDir . FILE_CATEGORY_TYPES;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_CATEGORY_TYPES);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('category_types_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('category_types_temp') . "(
                          id int(11),
                          name varchar(255),
                          key(id)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('category_types_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_category_types'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('category_types_temp') . "
                          TO " . $this->_getTableName('sinch_category_types'));

            $this->_logImportInfo("Finish parse " . FILE_CATEGORY_TYPES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');

    }

    public function parseCategories()
    {
        $imType = $this->_dataConf['replace_category'];
        $parseFile = $this->varDir . FILE_CATEGORIES;
        //$parseFile = $this->varDir . FILE_CATEGORIES_TEST;
        $field_terminated_char = $this->field_terminated_char;

        $this->im_type = $imType;

        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_CATEGORIES);

            $this->_getCategoryEntityTypeIdAndDefault_attribute_set_id();

            $categories_temp = $this->_getTableName('categories_temp');
            $catalog_category_entity = $this->_getTableName('catalog_category_entity');
            $catalog_category_entity_varchar = $this->_getTableName('catalog_category_entity_varchar');
            $catalog_category_entity_int = $this->_getTableName('catalog_category_entity_int');
            $sinch_categories_mapping_temp = $this->_getTableName('sinch_categories_mapping_temp');
            $sinch_categories_mapping = $this->_getTableName('sinch_categories_mapping');
            $sinch_categories = $this->_getTableName('sinch_categories');
            $category_types = $this->_getTableName('sinch_category_types');

            $_categoryEntityTypeId = $this->_categoryEntityTypeId;
            $_categoryDefault_attribute_set_id = $this->_categoryDefault_attribute_set_id;

            $name_attrid = $this->_getCategoryAttributeId('name');
            $is_anchor_attrid = $this->_getCategoryAttributeId('is_anchor');
            $image_attrid = $this->_getCategoryAttributeId('image');

            $attr_url_key = $this->_getCategoryAttributeId('url_key');
            $attr_display_mode = $this->_getCategoryAttributeId('display_mode');
            $attr_is_active = $this->_getCategoryAttributeId('is_active');
            $attr_include_in_menu = $this->_getCategoryAttributeId('include_in_menu');

            $this->loadCategoriesTemp($categories_temp, $parseFile, $field_terminated_char);
            $coincidence = $this->calculateCategoryCoincidence($categories_temp, $catalog_category_entity, $catalog_category_entity_varchar, $imType, $category_types);

            /**/
            if (!$this->check_loaded_data($parseFile, $categories_temp)) {
                $inf = mysqli_info();
                $this->_setErrorMessage('The Stock In The Channel data files do not appear to be in the correct format. Check file' . $parseFile . "(LOAD DATA ... " . $inf . ")");
                exit;
            }/**/

            echo(" => Coincidence = [" . count($coincidence) . "]\n");

            if (count($coincidence) == 1) // one store logic
            {
                echo("\n\n--------OLD LOGIC--------\n\n");
                if ($imType == "REWRITE") {
                    $rootCat = 2;

                    $rootCat = $this->truncateAllCateriesAndRecreateDefaults($rootCat, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                        $_categoryEntityTypeId, $_categoryDefault_attribute_set_id,
                        $name_attrid, $attr_url_key, $attr_display_mode, $attr_is_active, $attr_include_in_menu); // return $rootCat
                } else // if ($imType == "MERGE")
                {
                    $rootCat = $this->_getShopRootCategoryId();
                }

                $this->_rootCat = $rootCat;

                $this->setCategorySettings($categories_temp, $rootCat);
                $this->mapSinchCategories($sinch_categories_mapping, $catalog_category_entity, $categories_temp, $imType, $rootCat);
                $this->addCategoryData($categories_temp, $sinch_categories_mapping, $sinch_categories, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                    $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_is_active, $attr_include_in_menu, $is_anchor_attrid, $image_attrid, $imType, $rootCat);
            } else if (count($coincidence) > 1) // multistore logic
            {
                echo("\n\n\n====================================\nMULTISTORE LOGIC\n====================================\n\n\n");
                switch ($imType) {
                    case "REWRITE":
                        $this->rewriteMultistoreCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
                            $name_attrid, $attr_display_mode, $attr_url_key, $attr_include_in_menu, $attr_is_active, $image_attrid, $is_anchor_attrid,
                            $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $categories_temp);
                        break;
                    case "MERGE"  :
                        $this->mergeMultistoreCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
                            $name_attrid, $attr_display_mode, $attr_url_key, $attr_include_in_menu, $attr_is_active, $image_attrid, $is_anchor_attrid,
                            $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $categories_temp);
                        break;
                    default       :
                        $retcode = "error";
                };
            } else {
                echo("error");
            }

            $this->_logImportInfo("Finish parse " . FILE_CATEGORIES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');
        $this->_set_default_rootCategory();
        return $coincidence;
    }

    private function _getCategoryEntityTypeIdAndDefault_attribute_set_id()
    {
        if (!$this->_categoryEntityTypeId || !$this->_categoryDefault_attribute_set_id) {
            $sql = "
                    SELECT entity_type_id, default_attribute_set_id
                    FROM " . $this->_getTableName('eav_entity_type') . "
                    WHERE entity_type_code = 'catalog_category'
                    LIMIT 1
                   ";
            $result = $this->_doQuery($sql)->fetch();
            if ($result) {
                $this->_categoryEntityTypeId = $result['entity_type_id'];
                $this->_categoryDefault_attribute_set_id = $result['default_attribute_set_id'];
            }

        }
    }

    private function loadCategoriesTemp($categories_temp, $parseFile, $field_terminated_char)
    {
        $this->_doQuery("DROP TABLE IF EXISTS $categories_temp");

        $this->_doQuery("
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

        $this->_doQuery("
            LOAD DATA LOCAL INFILE '$parseFile' INTO TABLE $categories_temp
            FIELDS TERMINATED BY '$field_terminated_char' OPTIONALLY ENCLOSED BY '\"' LINES TERMINATED BY \"\r\n\" IGNORE 1 LINES");

        $this->_doQuery("ALTER TABLE $categories_temp ADD COLUMN include_in_menu TINYINT(1) NOT NULL DEFAULT 1");
        $this->_doQuery("UPDATE $categories_temp SET include_in_menu = 0 WHERE UCASE(is_hidden)='TRUE'");

        $this->_doQuery("ALTER TABLE $categories_temp ADD COLUMN is_anchor TINYINT(1) NOT NULL DEFAULT 1");
        $this->_doQuery("UPDATE $categories_temp SET level = (level+2) WHERE level >= 0");
    }

    private function calculateCategoryCoincidence($categories_temp, $catalog_category_entity, $catalog_category_entity_varchar, $imType, $category_types)
    {
        $rootCategories = $this->_doQuery("
            SELECT
                cce.entity_id,
                ccev.value AS category_name
            FROM $catalog_category_entity cce
            JOIN $catalog_category_entity_varchar ccev
                ON cce.entity_id = ccev.entity_id
                AND ccev.store_id = 0
                AND ccev.attribute_id = 41
            WHERE parent_id = 1
        ")->fetchAll();

        $OLD = [];

        foreach ($rootCategories as $rootCat) {
            $OLD[] = $rootCat['category_name'];
        }

        $newRootCat = $this->_doQuery("SELECT DISTINCT RootName FROM $categories_temp")->fetch();

        $NEW = [];

        if ($newRootCat) {
            $existsCoincidence[$newRootCat['RootName']] = TRUE;
        }

        echo("\nCalculate Category Coincidence...");

        return $existsCoincidence;
    }

    public function check_loaded_data($file, $table)
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

    public function file_strings_count($parseFile)
    {
        $f = fopen($parseFile, 'rb');
        $lines = 0;

        while (!feof($f)) {
        $lines += substr_count(fread($f, 8192), "\r\n");
        }

        fclose($f);
        return $lines;
    }

    public function table_rows_count($table)
    {
        $rowsCount = $this->_doQuery("select count(*) as cnt from " . $table)->fetch();
        return ($rowsCount['cnt']);
    }

    private function truncateAllCateriesAndRecreateDefaults($rootCat, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                                            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id,
                                                            $name_attrid, $attr_url_key, $attr_display_mode, $attr_is_active, $attr_include_in_menu)
    {
        $this->_doQuery('SET foreign_key_checks=0');

        $this->_doQuery("TRUNCATE $catalog_category_entity");
        $this->_doQuery("
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
                                (2, $_categoryDefault_attribute_set_id, 1, now(), now(), '1/2', 1, 1, 1, null, null)");

        $this->_doQuery("TRUNCATE $catalog_category_entity_varchar");
        $this->_doQuery("
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
                        (7, $attr_url_key, 0, 2, 'default-category')");

        $this->_doQuery("TRUNCATE $catalog_category_entity_int");
        $this->_doQuery("
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
                        (4, $attr_include_in_menu, 0, 2, 1)");

        return $rootCat;
    }

    private function _getShopRootCategoryId($cat_id = 0)
    {
        if ($rootCat = $this->_storeManager->getStore()->getRootCategoryId()) {
            return $rootCat;
        } else {
            $q = "SELECT
                entity_id
            FROM " . $this->_getTableName('catalog_category_entity_varchar') . "
            WHERE
                value='default-category'";
            $res = $this->_doQuery($q)->fetch();
            if ($res['entity_id'] > 0) {
                return $res['entity_id'];
            } else {
                $q = "SELECT entity_id
                FROM " . $this->_getTableName('catalog_category_entity') . "
                WHERE parent_id=" . $cat_id;
                $res = $this->_doQuery($q)->fetchAll();
                $count = 0;

                foreach ($res as $value) {
                    $count++;
                    $entity_id = $value['entity_id'];
                }

                if ($count > 1 || $count == 0) {
                    return ($cat_id);
                } else {
                    return $this->_getShopRootCategoryId($entity_id);
                }
            }
        }
    }

    private function setCategorySettings($categories_temp, $rootCat)
    {
        $this->_doQuery("
            UPDATE $categories_temp
            SET parent_store_category_id = $rootCat
            WHERE parent_store_category_id = 0");

        $storeCatIds = $this->_doQuery("SELECT store_category_id FROM $categories_temp")->fetchAll();

        foreach ($storeCatIds as $key => $storeCategory) {
            $store_category_id = $storeCategory['store_category_id'];

            $children_count = $this->count_children($store_category_id);
            $level = $this->get_category_level($store_category_id);

            $this->_doQuery("
                UPDATE $categories_temp
                SET children_count = $children_count,
                    level = $level
                WHERE store_category_id = $store_category_id");
        }
    }

    public function count_children($id)
    {
        $q = "SELECT store_category_id
            FROM " . $this->_getTableName('categories_temp') . "
            WHERE parent_store_category_id=" . $id;

        $childCates = $this->_doQuery($q)->fetchAll();

        $count = 0;

        if ($childCates) {
            foreach ($childCates as $childCate) {
                $count += $this->count_children($childCate['store_category_id']);
                $count++;
            }
        }

        return ($count);
    }

    public function get_category_level($id)
    {
        $q = "SELECT parent_store_category_id
            FROM " . $this->_getTableName('categories_temp') . "
            WHERE store_category_id=" . $id;

        $parentCate = $this->_doQuery($q)->fetch();

        $level = 1;

        while ($parentCate['parent_store_category_id'] != 0) {
            $q = "SELECT parent_store_category_id
                FROM " . $this->_getTableName('categories_temp') . "
                WHERE store_category_id=" . $parentCate['parent_store_category_id'];

            $parentCate = $this->_doQuery($q)->fetch();

            $level++;

            if ($level > 20) {
                break;
            }
        }

        return $level;
    }

    public function mapSinchCategories($sinch_categories_mapping, $catalog_category_entity, $categories_temp, $imType, $rootCat, $mapping_again = false)
    {
        $sinch_categories_mapping_temp = $this->_getTableName('sinch_categories_mapping_temp');

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories_mapping_temp");

        $this->_doQuery("
            CREATE TABLE $sinch_categories_mapping_temp
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
                )");

        $this->_doQuery("CREATE TABLE IF NOT EXISTS $sinch_categories_mapping LIKE $sinch_categories_mapping_temp");

        // added for mapping new sinch categories in merge && !UPDATE_CATEGORY_DATA mode
        if ((UPDATE_CATEGORY_DATA && $imType == "MERGE") || ($imType == "REWRITE")) {
            // backup Category ID in REWRITE mode
            if ($mapping_again) {
                $this->_doQuery("
                    INSERT IGNORE INTO $sinch_categories_mapping_temp
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
                    FROM $catalog_category_entity)");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.store_category_id             = c.store_category_id,
                        cmt.parent_store_category_id      = c.parent_store_category_id,
                        cmt.category_name                 = c.category_name,
                        cmt.order_number                  = c.order_number,
                        cmt.products_within_this_category = c.products_within_this_category");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity cce
                        ON cmt.parent_store_category_id = cce.store_category_id
                    SET cmt.shop_parent_id = cce.entity_id");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET shop_parent_id = " . $this->_rootCat . "
                    WHERE shop_parent_id = 0");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity cce
                        ON cmt.shop_entity_id = cce.entity_id
                    SET cce.parent_id = cmt.shop_parent_id");
            } else {
                $catalog_category_entity_backup = $this->_getTableName('sinch_category_backup');
                if (!$this->_checkCategoryBackupExist($catalog_category_entity_backup)) {
                    $catalog_category_entity_backup = $catalog_category_entity;
                }

                $this->_doQuery("
                    INSERT IGNORE INTO $sinch_categories_mapping_temp
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
                    FROM $catalog_category_entity_backup)");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.store_category_id             = c.store_category_id,
                        cmt.parent_store_category_id      = c.parent_store_category_id,
                        cmt.category_name                 = c.category_name,
                        cmt.order_number                  = c.order_number,
                        cmt.products_within_this_category = c.products_within_this_category");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity_backup cce
                        ON cmt.parent_store_category_id = cce.store_category_id
                    SET cmt.shop_parent_id = cce.entity_id");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET shop_parent_id = " . $this->_rootCat . "
                    WHERE shop_parent_id = 0");

                $this->_doQuery("
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity cce
                        ON cmt.shop_entity_id = cce.entity_id
                    SET cce.parent_id = cmt.shop_parent_id");
            }
            // (end) backup Category ID in REWRITE mode
        } else {
            $this->_doQuery("
                INSERT IGNORE INTO $sinch_categories_mapping_temp
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
                FROM $catalog_category_entity)");

            $this->_doQuery("
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $categories_temp c
                    ON cmt.shop_store_category_id = c.store_category_id
                SET
                    cmt.store_category_id             = c.store_category_id,
                    cmt.parent_store_category_id      = c.parent_store_category_id,
                    cmt.category_name                 = c.category_name,
                    cmt.order_number                  = c.order_number,
                    cmt.products_within_this_category = c.products_within_this_category");

            $this->_doQuery("
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $catalog_category_entity cce
                    ON cmt.parent_store_category_id = cce.store_category_id
                SET cmt.shop_parent_id = cce.entity_id");

            $this->_doQuery("
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $categories_temp c
                    ON cmt.shop_store_category_id = c.store_category_id
                SET shop_parent_id = " . $this->_rootCat . "
                WHERE shop_parent_id = 0");

            $this->_doQuery("
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $catalog_category_entity cce
                    ON cmt.shop_entity_id = cce.entity_id
                SET cce.parent_id = cmt.shop_parent_id
                WHERE cce.parent_id = 0 AND cce.store_category_id IS NOT NULL");
        }
        $this->_logImportInfo("Execute function mapSinchCategories");
        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories_mapping");
        $this->_doQuery("RENAME TABLE $sinch_categories_mapping_temp TO $sinch_categories_mapping");
    }

    private function addCategoryData($categories_temp, $sinch_categories_mapping, $sinch_categories, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                     $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_is_active, $attr_include_in_menu, $is_anchor_attrid, $image_attrid, $imType, $rootCat)
    {
        if (UPDATE_CATEGORY_DATA) {
            echo "Update category_entity \n";

            $q = "
                INSERT INTO $catalog_category_entity
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
                (SELECT
                    $_categoryDefault_attribute_set_id,
                    now(),
                    now(),
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
                )
                ON DUPLICATE KEY UPDATE
                    updated_at = now(),
                    store_category_id = c.store_category_id,
                    level = c.level,
                    children_count = c.children_count,
                    position = c.order_number,
                    parent_store_category_id = c.parent_store_category_id";
        } else {
            echo "\nInsert Ignore category_entity...";

            $q = "
                INSERT IGNORE INTO $catalog_category_entity
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
                (SELECT
                    $_categoryDefault_attribute_set_id,
                    now(),
                    now(),
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
                )";
        }
        $this->_doQuery($q);

        $this->mapSinchCategories($sinch_categories_mapping, $catalog_category_entity, $categories_temp, $imType, $rootCat, true);

        $categories = $this->_doQuery("SELECT entity_id, parent_id FROM $catalog_category_entity ORDER BY parent_id")->fetchAll();

        foreach ($categories as $key => $category) {
            $parent_id = $category['parent_id'];
            $entity_id = $category['entity_id'];

            $path = $this->culc_path($parent_id, $entity_id);

            $this->_doQuery("
                UPDATE $catalog_category_entity
                             SET path = '$path'
                             WHERE entity_id = $entity_id");
        }

        if (UPDATE_CATEGORY_DATA) {
            echo "\nUpdate category_data...";

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    0,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.category_name";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    1,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.category_name";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    0,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = 1";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    1,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = 1";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_include_in_menu,
                    0,
                    scm.shop_entity_id,
                    c.include_in_menu
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.include_in_menu";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    1,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.is_anchor";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    0,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.is_anchor";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $image_attrid,
                    0,
                    scm.shop_entity_id,
                    c.categories_image
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.categories_image";
            $this->_doQuery($q);

            //STP
            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetaTitleAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaTitle
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.MetaTitle";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetadescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaDescription
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.MetaDescription";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryDescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.Description
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.Description";
            $this->_doQuery($q);
        } else {
            echo "\nInsert Ignore category_data....";

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    0,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    0,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_include_in_menu,
                    0,
                    scm.shop_entity_id,
                    c.include_in_menu
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    0,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $image_attrid,
                    0,
                    scm.shop_entity_id,
                    c.categories_image
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetaTitleAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaTitle
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
               ";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetadescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaDescription
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
            ";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryDescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.Description
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
            ";
            $this->_doQuery($q);
        }

        $this->delete_old_sinch_categories_from_shop();

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories");
        $this->_doQuery("RENAME TABLE $categories_temp TO $sinch_categories");
    }

    public function culc_path($parent_id, $ent_id)
    {
        $path = '';
        $cat_id = $parent_id;
        $q = "SELECT
                parent_id
            FROM " . $this->_getTableName('catalog_category_entity') . "
            WHERE entity_id=" . $cat_id;

        $parentCate = $this->_doQuery($q)->fetch();
        while ($parentCate['parent_id']) {
            $path = $parentCate['parent_id'] . '/' . $path;
            $q = "SELECT
                    parent_id
                FROM " . $this->_getTableName('catalog_category_entity') . "
                WHERE entity_id=" . $parentCate['parent_id'];

            $parentCate = $this->_doQuery($q)->fetch();
        }
        if ($cat_id) {
            $path .= $cat_id . "/";
        }

        if ($path) {
            return ($path . $ent_id);
        } else {
            return ($ent_id);
        }

    }

    private function delete_old_sinch_categories_from_shop()
    {
        $q = "DELETE cat FROM " . $this->_getTableName('catalog_category_entity_varchar') . " cat
            JOIN " . $this->_getTableName('sinch_categories_mapping') . " scm
                ON cat.entity_id=scm.shop_entity_id
            WHERE
                (scm.shop_store_category_id is not null) AND
                (scm.store_category_id is null)";
        $this->_doQuery($q);

        $q = "DELETE cat FROM " . $this->_getTableName('catalog_category_entity_int') . " cat
            JOIN " . $this->_getTableName('sinch_categories_mapping') . " scm
                ON cat.entity_id=scm.shop_entity_id
            WHERE
                (scm.shop_store_category_id is not null) AND
                (scm.store_category_id is null)";
        $this->_doQuery($q);

        $q = "DELETE cat FROM " . $this->_getTableName('catalog_category_entity') . " cat
            JOIN " . $this->_getTableName('sinch_categories_mapping') . " scm
                ON cat.entity_id=scm.shop_entity_id
            WHERE
                (scm.shop_store_category_id is not null) AND
                (scm.store_category_id is null)";
        $this->_doQuery($q);

    }

    private function rewriteMultistoreCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                                 $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
                                                 $name_attrid, $attr_display_mode, $attr_url_key, $attr_include_in_menu, $attr_is_active, $image_attrid, $is_anchor_attrid,
                                                 $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $categories_temp)
    {
        echo("rewriteMultistoreCategories RUN\n");

            echo("    truncateAllCateriesAndCreateRoot start...");
        $this->truncateAllCateriesAndCreateRoot($catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_display_mode, $attr_url_key, $attr_include_in_menu, $attr_is_active);
        echo(" done.\n");

            echo("    createDefaultCategories start...");
        $this->createDefaultCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_display_mode, $attr_url_key, $attr_is_active, $attr_include_in_menu);
        echo(" done.\n");

            echo("    mapSinchCategoriesMultistore start...");
        $this->mapSinchCategoriesMultistore($sinch_categories_mapping_temp, $sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $categories_temp, $imType, $_categoryEntityTypeId, $name_attrid);
        echo(" done.\n");

            echo("    addCategoryDataMultistore start...");
        $this->addCategoryDataMultistore($categories_temp, $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
            $name_attrid, $attr_is_active, $attr_include_in_menu, $is_anchor_attrid, $image_attrid);
        echo(" done.\n");

            echo("rewriteMultistoreCategories DONE\n");
    }

    private function truncateAllCateriesAndCreateRoot($catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                                      $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_display_mode, $attr_url_key, $attr_include_in_menu, $attr_is_active)
    {
        $this->_doQuery('SET foreign_key_checks=0');

            $this->_doQuery("TRUNCATE $catalog_category_entity");
        $this->_doQuery("TRUNCATE $catalog_category_entity_varchar");
        $this->_doQuery("TRUNCATE $catalog_category_entity_int");

            $this->_doQuery("INSERT $catalog_category_entity
                    (entity_id, attribute_set_id, parent_id, created_at, updated_at,
                    path, position, level, children_count, store_category_id, parent_store_category_id)
                VALUES
                    (1, $_categoryDefault_attribute_set_id, 0, '0000-00-00 00:00:00', NOW(), '1', 0, 0, 1, NULL, NULL)");

                        $this->_doQuery("INSERT $catalog_category_entity_varchar
                    (value_id, attribute_id, store_id, entity_id, value)
                VALUES
                    (1, $name_attrid, 0, 1, 'Root Catalog'),
                    (2, $name_attrid, 1, 1, 'Root Catalog'),
                    (3, $attr_url_key, 0, 1, 'root-catalog')");

                        $this->_doQuery("INSERT $catalog_category_entity_int
                    (value_id, attribute_id, store_id, entity_id, value)
                VALUES
                    (1, $attr_include_in_menu, 0, 1, 1)");
    }

    private function createDefaultCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                             $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_display_mode, $attr_url_key, $attr_is_active, $attr_include_in_menu)
    {
        $i = 3; // 2 - is Default Category... not use.

        foreach ($coincidence as $key => $item) {
            $this->_doQuery("INSERT $catalog_category_entity
                        (entity_id, attribute_set_id, parent_id, created_at, updated_at,
                        path, position, level, children_count, store_category_id, parent_store_category_id)
                    VALUES
                        ($i, $_categoryDefault_attribute_set_id, 1, now(), now(), '1/$i', 1, 1, 1, NULL, NULL)");

                                $this->_doQuery("INSERT $catalog_category_entity_varchar
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($name_attrid,       0, $i, '$key'),
                        ($name_attrid,       1, $i, '$key'),
                        ($attr_display_mode, 1, $i, '$key'),
                        ($attr_url_key,      0, $i, '$key')");

                                $this->_doQuery("INSERT $catalog_category_entity_int
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($attr_is_active,       0, $i, 1),
                        ($attr_is_active,       1, $i, 1),
                        ($attr_include_in_menu, 0, $i, 1),
                        ($attr_include_in_menu, 1, $i, 1)");
            $i++;
        }
    }

    private function mapSinchCategoriesMultistore($sinch_categories_mapping_temp, $sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $categories_temp, $imType, $_categoryEntityTypeId, $name_attrid, $mapping_again = false)
    {
        echo("\n==========================================================================\nmapSinchCategoriesMultistore start... \n");

        $this->createMappingSinchTables($sinch_categories_mapping_temp, $sinch_categories_mapping);

        // backup Category ID in REWRITE mode
        if ($imType == "REWRITE" || (UPDATE_CATEGORY_DATA && $imType == "MERGE")) {
            if ($mapping_again) {
                $query = "
                    INSERT IGNORE INTO $sinch_categories_mapping_temp
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
                    FROM $catalog_category_entity)";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);

                            $query = "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.store_category_id             = c.store_category_id,
                        cmt.parent_store_category_id      = c.parent_store_category_id,
                        cmt.category_name                 = c.category_name,
                        cmt.order_number                  = c.order_number,
                        cmt.products_within_this_category = c.products_within_this_category";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);

                            $query = "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity cce
                        ON cmt.parent_store_category_id = cce.store_category_id
                    SET cmt.shop_parent_id = cce.entity_id";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);

                $query = "
                    SELECT DISTINCT
                        c.RootName, cce.entity_id
                    FROM $categories_temp c
                    JOIN $catalog_category_entity_varchar ccev
                        ON c.RootName = ccev.value
                        AND ccev.attribute_id = $name_attrid
                        AND ccev.store_id = 0
                    JOIN $catalog_category_entity cce
                        ON ccev.entity_id = cce.entity_id";

                echo("\n\n$query\n\n");
                $rootCategories = $this->_doQuery($query)->fetchAll();

                foreach ($rootCategories as $key => $rootCat) {
                    $root_id = $rootCat['entity_id'];
                    $root_name = $rootCat['RootName'];

                    $query = "
                        UPDATE $sinch_categories_mapping_temp cmt
                        JOIN $categories_temp c
                            ON cmt.shop_store_category_id = c.store_category_id
                        SET
                            cmt.shop_parent_id = $root_id,
                            cmt.shop_parent_store_category_id = $root_id,
                            cmt.parent_store_category_id = $root_id,
                            c.parent_store_category_id = $root_id
                        WHERE RootName = '$root_name'
                            AND cmt.shop_parent_id = 0";
                    echo("\n\n$query\n\n");
                    $this->_doQuery($query);
                }
            } else {
                $catalog_category_entity_backup = $this->_getTableName('sinch_category_backup');
                if (!$this->_checkCategoryBackupExist($catalog_category_entity_backup)) {
                    $catalog_category_entity_backup = $catalog_category_entity;
                }
                $query = "
                    INSERT IGNORE INTO $sinch_categories_mapping_temp
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
                    FROM $catalog_category_entity_backup)";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);

                            $query = "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.store_category_id             = c.store_category_id,
                        cmt.parent_store_category_id      = c.parent_store_category_id,
                        cmt.category_name                 = c.category_name,
                        cmt.order_number                  = c.order_number,
                        cmt.products_within_this_category = c.products_within_this_category";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);

                            $query = "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $catalog_category_entity_backup cce
                        ON cmt.parent_store_category_id = cce.store_category_id
                    SET cmt.shop_parent_id = cce.entity_id";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);

                $query = "
                    SELECT DISTINCT
                        c.RootName, cce.entity_id
                    FROM $categories_temp c
                    JOIN $catalog_category_entity_varchar ccev
                        ON c.RootName = ccev.value
                        AND ccev.attribute_id = $name_attrid
                        AND ccev.store_id = 0
                    JOIN $catalog_category_entity_backup cce
                        ON ccev.entity_id = cce.entity_id";
                echo("\n\n$query\n\n");
                $rootCategories = $this->_doQuery($query)->fetchAll();

                foreach ($rootCategories as $key => $rootCat) {
                    $root_id = $rootCat['entity_id'];
                    $root_name = $rootCat['RootName'];

                    $query = "
                        UPDATE $sinch_categories_mapping_temp cmt
                        JOIN $categories_temp c
                            ON cmt.shop_store_category_id = c.store_category_id
                        SET
                            cmt.shop_parent_id = $root_id,
                            cmt.shop_parent_store_category_id = $root_id,
                            cmt.parent_store_category_id = $root_id,
                            c.parent_store_category_id = $root_id
                        WHERE RootName = '$root_name'
                            AND cmt.shop_parent_id = 0";
                    echo("\n\n$query\n\n");
                    $this->_doQuery($query);
                }
            }
        // (end) backup Category ID in REWRITE mode
        } else {
            $query = "
                INSERT IGNORE INTO $sinch_categories_mapping_temp
                    (shop_entity_id, shop_attribute_set_id, shop_parent_id, shop_store_category_id, shop_parent_store_category_id)
                (SELECT entity_id, attribute_set_id, parent_id, store_category_id, parent_store_category_id
                FROM $catalog_category_entity)";
            $this->_doQuery($query);

            $query = "
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $categories_temp c
                    ON cmt.shop_store_category_id = c.store_category_id
                SET
                    cmt.store_category_id             = c.store_category_id,
                    cmt.parent_store_category_id      = c.parent_store_category_id,
                    cmt.category_name                 = c.category_name,
                    cmt.order_number                  = c.order_number,
                    cmt.products_within_this_category = c.products_within_this_category";
            echo("\n\n$query\n\n");
            $this->_doQuery($query);

                    $query = "
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $catalog_category_entity cce
                    ON cmt.parent_store_category_id = cce.store_category_id
                SET cmt.shop_parent_id = cce.entity_id";
            echo("\n\n$query\n\n");
            $this->_doQuery($query);

                    $query = "
                SELECT DISTINCT
                    c.RootName, cce.entity_id
                FROM $categories_temp c
                JOIN $catalog_category_entity_varchar ccev
                    ON c.RootName = ccev.value
                    AND ccev.attribute_id = $name_attrid
                    AND ccev.store_id = 0
                JOIN $catalog_category_entity cce
                    ON ccev.entity_id = cce.entity_id";
            echo("\n\n$query\n\n");

            $rootCategories = $this->_doQuery($query)->fetchAll();

            foreach ($rootCategories as $key => $rootCat) {
                $root_id = $rootCat['entity_id'];
                $root_name = $rootCat['RootName'];

                $query = "
                    UPDATE $sinch_categories_mapping_temp cmt
                    JOIN $categories_temp c
                        ON cmt.shop_store_category_id = c.store_category_id
                    SET
                        cmt.shop_parent_id = $root_id,
                        cmt.shop_parent_store_category_id = $root_id,
                        cmt.parent_store_category_id = $root_id,
                        c.parent_store_category_id = $root_id
                    WHERE RootName = '$root_name'
                        AND cmt.shop_parent_id = 0";
                echo("\n\n$query\n\n");
                $this->_doQuery($query);
            }
        }

        // added for mapping new sinch categories in merge && !UPDATE_CATEGORY_DATA mode
        if ((UPDATE_CATEGORY_DATA && $imType == "MERGE") || ($imType == "REWRITE")) $where = '';
        else $where = 'WHERE cce.parent_id = 0 AND cce.store_category_id IS NOT NULL';

        $query = "
            UPDATE $sinch_categories_mapping_temp cmt
            JOIN $catalog_category_entity cce
                ON cmt.shop_entity_id = cce.entity_id
            SET cce.parent_id = cmt.shop_parent_id
            $where";
        echo("\n\n$query\n\n");
        $this->_doQuery($query);
        $this->_logImportInfo("Execute function mapSinchCategoriesMultistore");
        $query = "DROP TABLE IF EXISTS $sinch_categories_mapping";
        echo("\n\n$query\n\n");
        $this->_doQuery($query);

        $query = "RENAME TABLE $sinch_categories_mapping_temp TO $sinch_categories_mapping";
        echo("\n\n$query\n\n");
        $this->_doQuery($query);

        echo("\nmapSinchCategoriesMultistore done... \n==========================================================================\n\n\n\n");
    }

    private function createMappingSinchTables($sinch_categories_mapping_temp, $sinch_categories_mapping)
    {
        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories_mapping_temp");
        $this->_doQuery("
            CREATE TABLE $sinch_categories_mapping_temp
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
                )");

                    $this->_doQuery("CREATE TABLE IF NOT EXISTS $sinch_categories_mapping LIKE $sinch_categories_mapping_temp");
    }

    private function addCategoryDataMultistore($categories_temp, $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int, $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
        $name_attrid, $attr_is_active, $attr_include_in_menu, $is_anchor_attrid, $image_attrid)
    {
        echo("\n\n\n\n*************************************************************\nmapSinchCategoriesMultistore start... \n");
        if (UPDATE_CATEGORY_DATA) {
            $ignore = '';
            $on_diplicate_key_update = "
                ON DUPLICATE KEY UPDATE
                    updated_at = now(),
                    store_category_id = c.store_category_id,
                    level = c.level,
                    children_count = c.children_count,
                    position = c.order_number,
                    parent_store_category_id = c.parent_store_category_id";
        } else {
            $ignore = 'IGNORE';
            $on_diplicate_key_update = '';
        }

        $query = "
            INSERT $ignore INTO $catalog_category_entity
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
            (SELECT
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
            ) $on_diplicate_key_update";
        echo("\n\n$query\n\n");
        $this->_doQuery($query);

        $this->mapSinchCategoriesMultistore($sinch_categories_mapping_temp, $sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $categories_temp, $imType, $_categoryEntityTypeId, $name_attrid, true);

        $categories = $this->_doQuery("SELECT entity_id, parent_id FROM $catalog_category_entity ORDER BY parent_id");
        foreach ($categories as $key => $category) {
            $parent_id = $category['parent_id'];
            $entity_id = $category['entity_id'];

            $path = $this->culcPathMultistore($parent_id, $entity_id, $catalog_category_entity);

            $this->_doQuery("
                UPDATE $catalog_category_entity
                SET path = '$path'
                WHERE entity_id = $entity_id");
        }

        if (UPDATE_CATEGORY_DATA) {
            echo "Update category_data \n";

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    0,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.category_name";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    1,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.category_name";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    0,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = 1";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    1,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = 1";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_include_in_menu,
                    0,
                    scm.shop_entity_id,
                    c.include_in_menu
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.include_in_menu";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    1,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.is_anchor";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    0,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.is_anchor";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $image_attrid,
                    0,
                    scm.shop_entity_id,
                    c.categories_image
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.categories_image";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetaTitleAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaTitle
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.MetaTitle";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetadescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaDescription
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.MetaDescription";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryDescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.Description
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.Description";
            $this->_doQuery($q);
        } else {
            echo "Insert ignore category_data \n";

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    0,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    0,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_include_in_menu,
                    0,
                    scm.shop_entity_id,
                    c.include_in_menu
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    0,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $image_attrid,
                    0,
                    scm.shop_entity_id,
                    c.categories_image
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetaTitleAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaTitle
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
               ";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetadescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaDescription
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
            ";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryDescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.Description
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
            ";
            $this->_doQuery($q);
        }

        $this->delete_old_sinch_categories_from_shop();
        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories\n\n");
        $this->_doQuery("RENAME TABLE $categories_temp TO $sinch_categories");
    }

    public function culcPathMultistore($parent_id, $ent_id, $catalog_category_entity)
    {
        $path = '';

        $cat_id = $parent_id;

        $q = "
            SELECT
                parent_id
            FROM $catalog_category_entity
            WHERE entity_id = $cat_id";

        $res = $this->_doQuery($q)->fetch();
        while ($res['parent_id']) {
            $path = $res['parent_id'] . '/' . $path;
            $parent_id = $res['parent_id'];

            $q = "
                SELECT
                    parent_id
                FROM $catalog_category_entity
                WHERE entity_id = $parent_id";
            $res = $this->_doQuery($q)->fetch();
        }

        if ($cat_id) $path .= $cat_id . "/";

        if ($path) $path .= $ent_id;
            else $path = $ent_id;

        return $path;
    }

    private function mergeMultistoreCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                               $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
                                               $name_attrid, $attr_display_mode, $attr_url_key, $attr_include_in_menu, $attr_is_active, $image_attrid, $is_anchor_attrid,
                                               $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $categories_temp)
    {
        echo("mergeMultistoreCategories RUN\n");

        $this->createNewDefaultCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_display_mode, $attr_url_key, $attr_is_active, $attr_include_in_menu);

        $this->mapSinchCategoriesMultistoreMerge($sinch_categories_mapping_temp, $sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $categories_temp, $imType, $_categoryEntityTypeId, $name_attrid);

        $this->addCategoryDataMultistoreMerge($categories_temp, $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
            $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
            $name_attrid, $attr_is_active, $attr_include_in_menu, $is_anchor_attrid, $image_attrid);

        echo("\n\n\nmergeMultistoreCategories DONE\n");
    }

    private function createNewDefaultCategories($coincidence, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                                $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $name_attrid, $attr_display_mode, $attr_url_key, $attr_is_active, $attr_include_in_menu)
    {
        echo("\n\n    ==========================================================================\n    createNewDefaultCategories start... \n");

        $old_cats = [];
        $res = $this->_doQuery("
            SELECT
                cce.entity_id,
                ccev.value AS category_name
            FROM $catalog_category_entity cce
            JOIN $catalog_category_entity_varchar ccev
                ON cce.entity_id = ccev.entity_id
                AND ccev.store_id = 0
                AND ccev.attribute_id = 41
            WHERE parent_id = 1")->fetchAll(); // 41 - category name

        foreach ($res as $key => $category) {
            $old_cats[] = $category['category_name'];
        }

        $max_entity_id = $this->_doQuery("SELECT MAX(entity_id) AS max_entity_id FROM $catalog_category_entity")->fetch();

        $i = $max_entity_id[max_entity_id] + 1;

        foreach ($coincidence as $key => $item) {
            echo("\nCoincidence: key = [$key]\n");

            if (in_array($key, $old_cats)) {
                echo("\nCONTINUE: key = [$key]   item = [$item]\n");
                continue;
            } else {
                echo("    CREATE NEW CATEGORY: key = [$key]   item = [$item]\n");
            }

                    $this->_doQuery("INSERT $catalog_category_entity
                        (entity_id, attribute_set_id, parent_id, created_at, updated_at,
                        path, position, level, children_count, store_category_id, parent_store_category_id)
                    VALUES
                        ($i, $_categoryDefault_attribute_set_id, 1, now(), now(), '1/$i', 1, 1, 1, NULL, NULL)");

                                $this->_doQuery("INSERT $catalog_category_entity_varchar
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($name_attrid,       0, $i, '$key'),
                        ($name_attrid,       1, $i, '$key'),
                        ($attr_display_mode, 1, $i, '$key'),
                        ($attr_url_key,      0, $i, '$key')");

                                $this->_doQuery("INSERT $catalog_category_entity_int
                        (attribute_id, store_id, entity_id, value)
                    VALUES
                        ($attr_is_active,       0, $i, 1),
                        ($attr_is_active,       1, $i, 1),
                        ($attr_include_in_menu, 0, $i, 1),
                        ($attr_include_in_menu, 1, $i, 1)");
            $i++;
        }

        echo("\nCreate New Default Categories -> DONE...\n==========================================================================\n");

    }

    private function mapSinchCategoriesMultistoreMerge($sinch_categories_mapping_temp, $sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $categories_temp, $imType, $_categoryEntityTypeId, $name_attrid)
    {
        echo("\n==========================================================================\nMap Sinch Categories Multistore -> START...\n");

        $this->createMappingSinchTables($sinch_categories_mapping_temp, $sinch_categories_mapping);

        $query = "
            INSERT IGNORE INTO $sinch_categories_mapping_temp
                (shop_entity_id, shop_attribute_set_id, shop_parent_id, shop_store_category_id, shop_parent_store_category_id)
            (SELECT entity_id, attribute_set_id, parent_id, store_category_id, parent_store_category_id
            FROM $catalog_category_entity)";
        echo("\n    $query\n");
        $this->_doQuery($query);

            $query = "
            UPDATE $sinch_categories_mapping_temp cmt
            JOIN $categories_temp c
                ON cmt.shop_store_category_id = c.store_category_id
            SET
                cmt.store_category_id             = c.store_category_id,
                cmt.parent_store_category_id      = c.parent_store_category_id,
                cmt.category_name                 = c.category_name,
                cmt.order_number                  = c.order_number,
                cmt.products_within_this_category = c.products_within_this_category";
        echo("\n    $query\n");
        $this->_doQuery($query);

            $query = "
            UPDATE $sinch_categories_mapping_temp cmt
            JOIN $catalog_category_entity cce
                ON cmt.parent_store_category_id = cce.store_category_id
            SET cmt.shop_parent_id = cce.entity_id";
        echo("\n    $query\n");
        $this->_doQuery($query);

            $query = "
            SELECT DISTINCT
                c.RootName, cce.entity_id
            FROM $categories_temp c
            JOIN $catalog_category_entity_varchar ccev
                ON c.RootName = ccev.value
                AND ccev.attribute_id = $name_attrid
                AND ccev.store_id = 0
            JOIN $catalog_category_entity cce
                ON ccev.entity_id = cce.entity_id";
        echo("\n    $query\n");
        $rootCategories = $this->_doQuery($query)->fetchAll();

        foreach ($rootCategories as $key => $rootCat) {
            $root_id = $rootCat['entity_id'];
            $root_name = $rootCat['RootName'];

            $query = "
                UPDATE $sinch_categories_mapping_temp cmt
                JOIN $categories_temp c
                    ON cmt.shop_store_category_id = c.store_category_id
                SET
                    cmt.shop_parent_id = $root_id,
                    cmt.shop_parent_store_category_id = $root_id,
                    cmt.parent_store_category_id = $root_id,
                    c.parent_store_category_id = $root_id
                WHERE RootName = '$root_name'
                    AND cmt.shop_parent_id = 0";
            echo("\n    $query\n");
            $this->_doQuery($query);
        }

        // added for mapping new sinch categories in merge && !UPDATE_CATEGORY_DATA mode
        if ((UPDATE_CATEGORY_DATA && $imType == "MERGE") || ($imType == "REWRITE")) $where = '';
        else $where = 'WHERE cce.parent_id = 0 AND cce.store_category_id IS NOT NULL';

        $query = "
            UPDATE $sinch_categories_mapping_temp cmt
            JOIN $catalog_category_entity cce
                ON cmt.shop_entity_id = cce.entity_id
            SET cce.parent_id = cmt.shop_parent_id
            $where";
        echo("\n    $query\n");
        $this->_doQuery($query);

        $query = "DROP TABLE IF EXISTS $sinch_categories_mapping";
        echo("\n    $query\n");
        $this->_doQuery($query);

        $query = "RENAME TABLE $sinch_categories_mapping_temp TO $sinch_categories_mapping";
        echo("\n    $query\n");
        $this->_doQuery($query);

        echo("\n    mapSinchCategoriesMultistore done... \n    ==========================================================================\n\n\n\n");
    }

    private function addCategoryDataMultistoreMerge($categories_temp, $sinch_categories_mapping_temp, $sinch_categories_mapping, $sinch_categories, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int,
                                                    $_categoryEntityTypeId, $_categoryDefault_attribute_set_id, $imType,
                                                    $name_attrid, $attr_is_active, $attr_include_in_menu, $is_anchor_attrid, $image_attrid)
    {
        echo("\n\n\n\n    *************************************************************\n    addCategoryDataMultistoreMerge start... \n");

            if (UPDATE_CATEGORY_DATA) {
            $ignore = '';
            $on_diplicate_key_update = "
                ON DUPLICATE KEY UPDATE
                    updated_at = now(),
                    store_category_id = c.store_category_id,
                    level = c.level,
                    children_count = c.children_count,
                    position = c.order_number,
                    parent_store_category_id = c.parent_store_category_id";
        } else {
            $ignore = 'IGNORE';
            $on_diplicate_key_update = '';
        }

        $query = "
            INSERT $ignore INTO $catalog_category_entity
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
            (SELECT
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
            ) $on_diplicate_key_update";
        echo("\n\n    $query\n\n");
        $this->_doQuery($query);

        $this->mapSinchCategoriesMultistoreMerge($sinch_categories_mapping_temp, $sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $categories_temp, $imType, $_categoryEntityTypeId, $name_attrid);

        $categories = $this->_doQuery("SELECT entity_id, parent_id FROM $catalog_category_entity ORDER BY parent_id")->fetchAll();
        foreach ($categories as $key => $category) {
            $parent_id = $category['parent_id'];
            $entity_id = $category['entity_id'];

            $path = $this->culcPathMultistore($parent_id, $entity_id, $catalog_category_entity);

            $this->_doQuery("
                UPDATE $catalog_category_entity
                SET path = '$path'
                WHERE entity_id = $entity_id");
        }

        if (UPDATE_CATEGORY_DATA) {
            echo "Update category_data \n";

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    0,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.category_name";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $name_attrid,
                    1,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.category_name";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    0,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = 1";

            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    1,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = 1";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_include_in_menu,
                    0,
                    scm.shop_entity_id,
                    c.include_in_menu
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.include_in_menu";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    1,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.is_anchor";
            $this->_doQuery($q);

                    $q = "
                INSERT INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    0,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.is_anchor";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $image_attrid,
                    0,
                    scm.shop_entity_id,
                    c.categories_image
                    FROM $categories_temp c
                    JOIN $sinch_categories_mapping scm
                        ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                    value = c.categories_image";
            $this->_doQuery($q);

            //STP
            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryEntityTypeId,
                     $this->_categoryMetaTitleAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaTitle
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.MetaTitle";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetadescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaDescription
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.MetaDescription";
            $this->_doQuery($q);

            $q = "
                INSERT INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryDescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.Description
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
                ON DUPLICATE KEY UPDATE
                     value = c.Description";
            $this->_doQuery($q);
        } else {
            echo "Insert ignore category_data \n";

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                            $name_attrid,
                    0,
                    scm.shop_entity_id,
                    c.category_name
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_is_active,
                    0,
                    scm.shop_entity_id,
                    1
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $attr_include_in_menu,
                    0,
                    scm.shop_entity_id,
                    c.include_in_menu
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_int
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $is_anchor_attrid,
                    0,
                    scm.shop_entity_id,
                    c.is_anchor
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

                    $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                        attribute_id,
                        store_id,
                        entity_id,
                        value
                    )
                (SELECT
                    $image_attrid,
                    0,
                    scm.shop_entity_id,
                    c.categories_image
                FROM $categories_temp c
                JOIN $sinch_categories_mapping scm
                    ON c.store_category_id = scm.store_category_id
                )";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetaTitleAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaTitle
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
               ";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryMetadescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.MetaDescription
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
            ";
            $this->_doQuery($q);

            $q = "
                INSERT IGNORE INTO $catalog_category_entity_varchar
                    (
                     attribute_id,
                     store_id,
                     entity_id,
                     value
                    )
                (SELECT
                     $this->_categoryDescriptionAttrId,
                     0,
                     scm.shop_entity_id,
                     c.Description
                 FROM $categories_temp c
                 JOIN $sinch_categories_mapping scm
                     ON c.store_category_id = scm.store_category_id
                )
            ";
            $this->_doQuery($q);
        }

        $this->_doQuery("DROP TABLE IF EXISTS $sinch_categories\n\n");
        $this->_doQuery("RENAME TABLE $categories_temp TO $sinch_categories");

        $this->deleteOldSinchCategoriesFromShopMerge($sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int);

        echo("\n    addCategoryDataMultistoreMerge done... \n    *************************************************************\n");

    }

    private function deleteOldSinchCategoriesFromShopMerge($sinch_categories_mapping, $catalog_category_entity, $catalog_category_entity_varchar, $catalog_category_entity_int)
    {

        echo("\n\n\n\n    +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++\n    deleteOldSinchCategoriesFromShopMerge start... \n");

            $query = "DROP TABLE IF EXISTS delete_cats";
        echo("\n    $query\n");
        $this->_doQuery($query);

            $delete_cats = $this->_getTableName('delete_cats');
        $sinch_categories = $this->_getTableName('sinch_categories');

        $query = "
CREATE TABLE $delete_cats

SELECT entity_id
FROM $catalog_category_entity cce
WHERE cce.entity_id NOT IN
    (
    SELECT cce2.entity_id
    FROM $catalog_category_entity cce2
    JOIN $sinch_categories sc
        ON cce2.store_category_id = sc.store_category_id
    )
    AND cce.store_category_id IS NOT NULL
;";

        echo("\n    $query\n");
        $this->_doQuery($query);

            $query = "DELETE cce FROM $catalog_category_entity cce JOIN $delete_cats dc USING(entity_id)";
        echo("\n    $query\n");
        $this->_doQuery($query);

            $query = "DROP TABLE IF EXISTS $delete_cats";
        echo("\n    $query\n");

        echo("\n    deleteOldSinchCategoriesFromShopMerge done... \n    +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++\n\n\n");

    }

    private function _set_default_rootCategory()
    {
        $q = "UPDATE " . $this->_getTableName('store_group') . " csg
            LEFT JOIN " . $this->_getTableName('catalog_category_entity') . " cce
            ON csg.root_category_id = cce.entity_id
            SET csg.root_category_id=(SELECT entity_id FROM " . $this->_getTableName('catalog_category_entity') . " WHERE parent_id = 1 LIMIT 1)
            WHERE csg.root_category_id > 0 AND cce.entity_id IS NULL";
        $this->_doQuery($q);
    }

    public function parseCategoryFeatures()
    {

        $parseFile = $this->varDir . FILE_CATEGORIES_FEATURES;
        if (filesize($parseFile) || $this->_ignore_category_features) {
            $this->_logImportInfo("Start parse " . FILE_CATEGORIES_FEATURES);
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('categories_features_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('categories_features_temp') . " (
                                category_feature_id int(11),
                                store_category_id int(11),
                                feature_name varchar(50),
                                display_order_number int(11),
                                KEY(store_category_id),
                                KEY(category_feature_id)
                          )
                        ");

            if (!$this->_ignore_category_features) {
                $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->_getTableName('categories_features_temp') . "
                              FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES ");
            }
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_categories_features'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('categories_features_temp') . "
                          TO " . $this->_getTableName('sinch_categories_features'));

            $this->_logImportInfo("Finish parse " . FILE_CATEGORIES_FEATURES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');
    }

    public function parseDistributors()
    {

        $parseFile = $this->varDir . FILE_DISTRIBUTORS;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_DISTRIBUTORS);
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('distributors_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('distributors_temp') . "(
                              distributor_id int(11),
                              distributor_name varchar(255),
                              website varchar(255),
                              KEY(distributor_id)
                          )
                        ");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('distributors_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_distributors'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('distributors_temp') . "
                          TO " . $this->_getTableName('sinch_distributors'));

            $this->_logImportInfo("Finish parse " . FILE_DISTRIBUTORS);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');
    }

    public function parseDistributorsStockAndPrice()
    {
        $parseFile = $this->varDir . FILE_DISTRIBUTORS_STOCK_AND_PRICES;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_DISTRIBUTORS_STOCK_AND_PRICES);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('distributors_stock_and_price_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('distributors_stock_and_price_temp') . "(
                          `store_product_id` int(11) DEFAULT NULL,
                          `distributor_id` int(11) DEFAULT NULL,
                          `stock` int(11) DEFAULT NULL,
                          `cost` decimal(15,4) DEFAULT NULL,
                          `distributor_sku` varchar(255) DEFAULT NULL,
                          `distributor_category` varchar(50) DEFAULT NULL,
                          `eta` varchar(50) DEFAULT NULL,
                          UNIQUE KEY `product_distri` (store_product_id, distributor_id)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('distributors_stock_and_price_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_distributors_stock_and_price'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('distributors_stock_and_price_temp') . "
                          TO " . $this->_getTableName('sinch_distributors_stock_and_price'));

            $this->_logImportInfo("Finish parse " . FILE_DISTRIBUTORS_STOCK_AND_PRICES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');

    }

############################### ##################################################################

    public function parseProductContracts()
    {
        $parseFile = $this->varDir . FILE_PRODUCT_CONTRACTS;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_PRODUCT_CONTRACTS);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('product_contracts_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('product_contracts_temp') . "(
                          `store_product_id` int(11) DEFAULT NULL,
                          `contract_id` varchar(50) DEFAULT NULL,
                          KEY `store_product_id` (store_product_id)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('product_contracts_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_product_contracts'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('product_contracts_temp') . "
                          TO " . $this->_getTableName('sinch_product_contracts'));

            $this->_logImportInfo("Finish parse " . FILE_PRODUCT_CONTRACTS);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');

    }

    public function parseEANCodes()
    {

        $parseFile = $this->varDir . FILE_EANCODES;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_EANCODES);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('ean_codes_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('ean_codes_temp') . "(
                           product_id int(11),
                           ean_code varchar(255),
                           KEY(product_id)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('ean_codes_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_ean_codes'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('ean_codes_temp') . "
                          TO " . $this->_getTableName('sinch_ean_codes'));

            $this->_logImportInfo("Finish parse " . FILE_EANCODES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');
    }

    public function parseManufacturers()
    {
        $parseFile = $this->varDir . FILE_MANUFACTURERS;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_MANUFACTURERS);
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('manufacturers_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('manufacturers_temp') . "(
                                      sinch_manufacturer_id int(11),
                                      manufacturer_name varchar(255),
                                      manufacturers_image varchar(255),
                                      shop_option_id int(11),
                                      KEY(sinch_manufacturer_id),
                                      KEY(shop_option_id),
                                      KEY(manufacturer_name)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('manufacturers_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $q = "DELETE aov
                FROM " . $this->_getTableName('eav_attribute_option') . " ao
                JOIN " . $this->_getTableName('eav_attribute_option_value') . " aov
                    ON ao.option_id=aov.option_id left
                JOIN " . $this->_getTableName('manufacturers_temp') . " mt
                    ON aov.value=mt.manufacturer_name
                WHERE
                    ao.attribute_id=" . $this->_getProductAttributeId('manufacturer') . " AND
                    mt.manufacturer_name is null";
            $this->_doQuery($q);

            $q = "DELETE ao
                FROM " . $this->_getTableName('eav_attribute_option') . " ao
                LEFT JOIN " . $this->_getTableName('eav_attribute_option_value') . " aov
                    ON ao.option_id=aov.option_id
                WHERE
                    attribute_id=" . $this->_getProductAttributeId('manufacturer') . " AND
                    aov.option_id is null";
            $this->_doQuery($q);

            $q = "SELECT
                    m.sinch_manufacturer_id,
                    m.manufacturer_name,
                    m.manufacturers_image
                FROM " . $this->_getTableName('manufacturers_temp') . " m
                LEFT JOIN " . $this->_getTableName('eav_attribute_option_value') . " aov
                    ON m.manufacturer_name=aov.value
                WHERE aov.value  IS NULL";
            $res = $this->_doQuery($q)->fetchAll();

            foreach ($res as $key => $row) {
                $q0 = "INSERT INTO " . $this->_getTableName('eav_attribute_option') . "
                        (attribute_id)
                     VALUES(" . $this->_getProductAttributeId('manufacturer') . ")";
                $quer0 = $this->_doQuery($q0);

                $q2 = "INSERT INTO " . $this->_getTableName('eav_attribute_option_value') . "(
                        option_id,
                        value
                     )(
                       SELECT
                        max(option_id) as option_id,
                        " . $this->_connection->quote($row['manufacturer_name']) . "
                       FROM " . $this->_getTableName('eav_attribute_option') . "
                       WHERE attribute_id=" . $this->_getProductAttributeId('manufacturer') . "
                     )
                    ";
                $quer2 = $this->_doQuery($q2);

            }

            $q = "UPDATE " . $this->_getTableName('manufacturers_temp') . " mt
                JOIN  " . $this->_getTableName('eav_attribute_option_value') . " aov
                    ON mt.manufacturer_name=aov.value
                JOIN " . $this->_getTableName('eav_attribute_option') . " ao
                    ON ao.option_id=aov.option_id
                SET mt.shop_option_id=aov.option_id
                WHERE ao.attribute_id=" . $this->_getProductAttributeId('manufacturer');
            $this->_doQuery($q);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_manufacturers'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('manufacturers_temp') . "
                          TO " . $this->_getTableName('sinch_manufacturers'));
            $this->_logImportInfo("Finish parse " . FILE_MANUFACTURERS);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');
    }

    public function parseRelatedProducts()
    {

        $parseFile = $this->varDir . FILE_RELATED_PRODUCTS;
        if (filesize($parseFile) || $this->_ignore_product_related) {
            $this->_logImportInfo("Start parse " . FILE_RELATED_PRODUCTS);
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('related_products_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('related_products_temp') . "(
                                 sinch_product_id int(11),
                                 related_sinch_product_id int(11),
                                 store_product_id int(11) default null,
                                 store_related_product_id int(11) default null,
                                 entity_id int(11),
                                 related_entity_id int(11),
                                 KEY(sinch_product_id),
                                 KEY(related_sinch_product_id),
                                 KEY(store_product_id)
                                     )DEFAULT CHARSET=utf8");
            if (!$this->_ignore_product_related) {
                $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->_getTableName('related_products_temp') . "
                              FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES ");
            }
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_related_products'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('related_products_temp') . "
                          TO " . $this->_getTableName('sinch_related_products'));

            $this->_logImportInfo("Finish parse " . FILE_RELATED_PRODUCTS);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(" ");
    }

    public function parseProductFeatures()
    {

        $parseFile = $this->varDir . FILE_PRODUCT_FEATURES;
        if (filesize($parseFile) || $this->_ignore_product_features) {
            $this->_logImportInfo("Start parse " . FILE_PRODUCT_FEATURES);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('product_features_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('product_features_temp') . "(
                            product_feature_id int(11),
                            sinch_product_id int(11),
                            restricted_value_id int(11),
                            KEY(sinch_product_id),
                            KEY(restricted_value_id)
                          )
                        ");
            if (!$this->_ignore_product_features) {
                $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->_getTableName('product_features_temp') . "
                              FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES ");
            }
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_product_features'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('product_features_temp') . "
                          TO " . $this->_getTableName('sinch_product_features'));

            $this->_logImportInfo("Finish parse " . FILE_PRODUCT_FEATURES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(" ");
    }

    public function parseProductCategories()
    {
        $parseFile = $this->varDir . FILE_PRODUCT_CATEGORIES;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_PRODUCT_CATEGORIES);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('product_categories_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('product_categories_temp') . "(
                          store_product_id int(11),
                          store_category_id int(11),
                          key(store_product_id),
                          key(store_category_id)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('product_categories_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_product_categories'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('product_categories_temp') . "
                          TO " . $this->_getTableName('sinch_product_categories'));

            $this->_logImportInfo("Finish parse " . FILE_PRODUCT_CATEGORIES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(' ');

    }

    public function parseProducts($coincidence)
    {
        echo("\n    --Parse Products 1\n");

        $replace_merge_product = $this->_dataConf['replace_product'];

        $parseFile = $this->varDir . FILE_PRODUCTS;
        //$parseFile = $this->varDir . FILE_PRODUCTS_TEST;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_PRODUCTS);
            echo("\n    --Parse Products 2\n");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('products_temp'));
            if ($this->product_file_format == "NEW") {
                $this->_doQuery("CREATE TABLE " . $this->_getTableName('products_temp') . "(
                             store_product_id int(11),
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
                             KEY(store_product_id),
                             KEY(sinch_manufacturer_id)
                          )DEFAULT CHARSET=utf8
                        ");
            } elseif ($this->product_file_format == "OLD") {
                $this->_doQuery("CREATE TABLE " . $this->_getTableName('products_temp') . "(
                              store_category_product_id int(11),
                              store_product_id int(11),
                              sinch_product_id int(11),
                              product_sku varchar(255),
                              product_name varchar(255),
                              sinch_manufacturer_id int(11),
                              store_category_id int(11),
                              main_image_url varchar(255),
                              thumb_image_url varchar(255),
                              specifications text,
                              description text,
                              search_cache text,
                              spec_characte_u_count int(11),
                              description_type varchar(50),
                              medium_image_url varchar(255),
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
                              KEY(store_category_product_id),
                              KEY(store_product_id),
                              KEY(sinch_manufacturer_id),
                              KEY(store_category_id)
                           )DEFAULT CHARSET=utf8
                         ");

            }
            echo("\n    --Parse Products 3\n");
            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('products_temp') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            if ($this->product_file_format == "NEW") {
                $this->_doQuery("ALTER TABLE " . $this->_getTableName('products_temp') . "
                          ADD COLUMN sinch_product_id int(11) AFTER store_product_id
                         ");
                $this->_doQuery("UPDATE " . $this->_getTableName('products_temp') . "
                          SET sinch_product_id=store_product_id
                         ");

                $this->_doQuery("ALTER TABLE " . $this->_getTableName('products_temp') . "
                            ADD COLUMN store_category_id int(11) AFTER sinch_manufacturer_id
                        ");
                $this->_doQuery("ALTER TABLE " . $this->_getTableName('products_temp') . "
                            ADD KEY(store_category_id)
                        ");
                $this->_doQuery("UPDATE " . $this->_getTableName('products_temp') . "
                          SET product_name = Title WHERE Title != ''
                        ");
                $this->_doQuery("UPDATE " . $this->_getTableName('products_temp') . " pt
                    JOIN " . $this->_getTableName('sinch_product_categories') . " spc
                    SET pt.store_category_id=spc.store_category_id
                    WHERE pt.store_product_id=spc.store_product_id
                    ");
                $this->_doQuery("UPDATE " . $this->_getTableName('products_temp') . "
                          SET main_image_url = medium_image_url WHERE main_image_url = ''
                         ");
            }

            echo("\n    --Parse Products 4\n");

            echo("\n    --Parse Products 5\n");
            $this->_doQuery("UPDATE " . $this->_getTableName('products_temp') . "
                          SET products_date_added=now(), products_last_modified=now()");
            echo("\n    --Parse Products 6\n");
            $this->_doQuery("UPDATE " . $this->_getTableName('products_temp') . " p
                          JOIN " . $this->_getTableName('sinch_manufacturers') . " m
                            ON p.sinch_manufacturer_id=m.sinch_manufacturer_id
                          SET p.manufacturer_name=m.manufacturer_name");
            echo("\n    --Parse Products 7\n");
            if ($this->current_import_status_statistic_id) {
                $res = $this->_doQuery("SELECT COUNT(*) AS cnt
                                     FROM " . $this->_getTableName('products_temp'))->fetch();
                $this->_doQuery("UPDATE " . $this->import_status_statistic_table . "
                              SET number_of_products=" . $res['cnt'] . "
                              WHERE id=" . $this->current_import_status_statistic_id);
            }

            if ($replace_merge_product == "REWRITE") {
                $this->_doQuery("DELETE FROM " . $this->_getTableName('catalog_product_entity'));
                $this->_doQuery("SET FOREIGN_KEY_CHECKS=0");
                $this->_doQuery("TRUNCATE " . $this->_getTableName('catalog_product_entity'));
                $this->_doQuery("SET FOREIGN_KEY_CHECKS=1");
            }

            echo("\n    --Parse Products 8\n");
            $this->addProductsWebsite();
            $this->mapSinchProducts($replace_merge_product);
            echo("\n    --Parse Products 9\n");

            if (count($coincidence) == 1) {
                $this->replaceMagentoProducts();
            } else {
                echo("\n\n\n\n\n\n$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$ [" . $this->im_type . "] $$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$\n\n\n\n"); //exit;
                switch ($this->im_type) {
                    case "REWRITE":
                        $this->replaceMagentoProductsMultistore($coincidence);
                        break;
                    case "MERGE":
                        $this->replaceMagentoProductsMultistoreMERGE($coincidence);
                        break;
                }
            }
            echo("\n    --Parse Products 10\n");

            $this->mapSinchProducts($replace_merge_product, true);
            $this->addManufacturer_attribute();
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_products'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('products_temp') . "
                          TO " . $this->_getTableName('sinch_products'));
            $this->_logImportInfo("Finish parse " . FILE_PRODUCTS);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(" ");
        echo("\n    --Parse Products 11\n");
    }

    public function addProductsWebsite()
    {
        $this->_doQuery(" DROP TABLE IF EXISTS " . $this->_getTableName('products_website_temp'));

        $this->_doQuery("
                CREATE TABLE `" . $this->_getTableName('products_website_temp') . "` (
                    `id` int(10) unsigned NOT NULL auto_increment,
                    store_product_id int(11),
                    sinch_product_id int(11),
                    `website` int(11) default NULL,
                    `website_id` int(11) default NULL,
                    PRIMARY KEY  (`id`),
                    KEY store_product_id (`store_product_id`)
                )
                ");
        $result = $this->_doQuery("SELECT
                                    website_id,
                                    store_id as website
                                FROM " . $this->_getTableName('store') . "
                                WHERE code!='admin'
                              ")->fetchAll(); //  where code!='admin' was adder for editing Featured products;

        foreach ($result as $key => $store) {
            $sql = "INSERT INTO " . $this->_getTableName('products_website_temp') . " (
                        store_product_id,
                        sinch_product_id,
                        website,
                        website_id
                    )(
                      SELECT
                        distinct
                        store_product_id,
                        sinch_product_id,
                        {$store['website']},
                        {$store['website_id']}
                      FROM " . $this->_getTableName('products_temp') . "
                    )";
            $result2 = $this->_doQuery($sql);
        }
    }

    public function mapSinchProducts($mode = 'MERGE', $mapping_again = false)
    {
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_products_mapping_temp'));
        $this->_doQuery("CREATE TABLE " . $this->_getTableName('sinch_products_mapping_temp') . " (
                      entity_id int(11) unsigned NOT NULL,
                      manufacturer_option_id int(11),
                      manufacturer_name varchar(255),
                      shop_store_product_id int(11),
                      shop_sinch_product_id int(11),
                      sku varchar(64) default NULL,
                      store_product_id int(11),
                      sinch_product_id int(11),
                      product_sku varchar(255),
                      sinch_manufacturer_id int(11),
                      sinch_manufacturer_name varchar(255),
                      KEY entity_id (entity_id),
                      KEY manufacturer_option_id (manufacturer_option_id),
                      KEY manufacturer_name (manufacturer_name),
                      KEY store_product_id (store_product_id),
                      KEY sinch_product_id (sinch_product_id),
                      KEY sku (sku),
                      UNIQUE KEY(entity_id)
                          )
                          ");
        $this->_doQuery("CREATE TABLE IF NOT EXISTS " . $this->_getTableName('sinch_products_mapping') . "
                      LIKE " . $this->_getTableName('sinch_products_mapping_temp'));
        $productEntityTable = $this->_getTableName('catalog_product_entity');

        // backup Product ID in REWRITE mode
        if ($mode == 'REWRITE' && !$mapping_again) {
            $productEntityTable = $this->_getTableName('sinch_product_backup');
        }
        // (end) backup Product ID in REWRITE mode

        $result = $this->_doQuery("
                                INSERT ignore INTO " . $this->_getTableName('sinch_products_mapping_temp') . " (
                                    entity_id,
                                    sku,
                                    shop_store_product_id,
                                    shop_sinch_product_id
                                )(SELECT
                                    entity_id,
                                    sku,
                                    store_product_id,
                                    sinch_product_id
                                  FROM " . $productEntityTable . "
                                 )
                              ");

        $this->addManufacturers(1);

        $q = "UPDATE " . $this->_getTableName('sinch_products_mapping_temp') . " pmt
            JOIN " . $this->_getTableName('catalog_product_index_eav') . " cpie
                ON pmt.entity_id=cpie.entity_id
            JOIN " . $this->_getTableName('eav_attribute_option_value') . " aov
                ON cpie.value=aov.option_id
            SET
                manufacturer_option_id=cpie.value,
                manufacturer_name=aov.value
            WHERE cpie.attribute_id=" . $this->_getProductAttributeId('manufacturer');
        $this->_doQuery($q);

        $q = "UPDATE " . $this->_getTableName('sinch_products_mapping_temp') . " pmt
            JOIN " . $this->_getTableName('products_temp') . " p
                ON pmt.sku=p.product_sku
            SET
                pmt.store_product_id=p.store_product_id,
                pmt.sinch_product_id=p.sinch_product_id,
                pmt.product_sku=p.product_sku,
                pmt.sinch_manufacturer_id=p.sinch_manufacturer_id,
                pmt.sinch_manufacturer_name=p.manufacturer_name";

        $this->_doQuery($q);

        $q = "UPDATE " . $this->_getTableName('catalog_product_entity') . " cpe
            JOIN " . $this->_getTableName('sinch_products_mapping_temp') . " pmt
                ON cpe.entity_id=pmt.entity_id
            SET cpe.store_product_id=pmt.store_product_id,
                cpe.sinch_product_id=pmt.sinch_product_id
            WHERE
                cpe.sinch_product_id IS NULL
                AND pmt.sinch_product_id IS NOT NULL
                AND cpe.store_product_id IS NULL
                AND pmt.store_product_id IS NOT NULL";
        $this->_doQuery($q);

        $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_products_mapping'));
        $this->_doQuery("RENAME TABLE " . $this->_getTableName('sinch_products_mapping_temp') . "
                      TO " . $this->_getTableName('sinch_products_mapping'));
    }

    public function addManufacturers($delete_eav = null)
    {
        // this cleanup is not needed due to foreign keys
        if (!$delete_eav) {
            $result = $this->_doQuery("
                                    DELETE FROM " . $this->_getTableName('catalog_product_index_eav') . "
                                    WHERE attribute_id = " . $this->_getProductAttributeId('manufacturer')//." AND store_id = ".$websiteId
            );
        }
        $this->addManufacturer_attribute();

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_index_eav') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                  INNER JOIN " . $this->_getTableName('sinch_manufacturers') . " mn
                                    ON b.sinch_manufacturer_id=mn.sinch_manufacturer_id
                                  WHERE mn.shop_option_id IS NOT NULL
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = mn.shop_option_id
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_index_eav') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                  INNER JOIN " . $this->_getTableName('sinch_manufacturers') . " mn
                                    ON b.sinch_manufacturer_id=mn.sinch_manufacturer_id
                                  WHERE mn.shop_option_id IS NOT NULL
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = mn.shop_option_id
                              ");
    }

    private function _getProductAttributeId($attributeCode)
    {
        return $this->_getAttributeId($attributeCode, 'catalog_product');
    }

    private function addManufacturer_attribute()
    {
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('manufacturer') . ",
                                    0,
                                    a.entity_id,
                                    pm.manufacturer_option_id
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('sinch_products_mapping') . " pm
                                    ON a.entity_id = pm.entity_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = pm.manufacturer_option_id
                              ");
    }

    public function replaceMagentoProducts()
    {
        $result = $this->_doQuery("DELETE cpe
                                FROM " . $this->_getTableName('catalog_product_entity') . " cpe
                                JOIN " . $this->_getTableName('sinch_products_mapping') . " pm
                                    ON cpe.entity_id=pm.entity_id
                                WHERE pm.shop_store_product_id IS NOT NULL
                                    AND pm.store_product_id IS NULL
                              ");

        //Inserting new products and updating old others.
        $this->_getProductDefaulAttributeSetId();
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity') . " (
                                    entity_id,
                                    attribute_set_id,
                                    type_id,
                                    sku,
                                    updated_at,
                                    has_options,
                                    store_product_id,
                                    sinch_product_id
                                )(SELECT
                                     pm.entity_id,
                                     $this->defaultAttributeSetId,
                                     'simple',
                                     a.product_sku,
                                     NOW(),
                                     0,
                                     a.store_product_id,
                                     a.sinch_product_id
                                  FROM " . $this->_getTableName('products_temp') . " a
                                  LEFT JOIN " . $this->_getTableName('sinch_products_mapping') . " pm
                                     ON a.store_product_id=pm.store_product_id
                                     AND a.sinch_product_id=pm.sinch_product_id
                                  WHERE pm.entity_id IS NOT NULL
                                )
                                ON DUPLICATE KEY UPDATE
                                    sku= a.product_sku,
                                    store_product_id=a.store_product_id,
                                    sinch_product_id=a.sinch_product_id
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity') . " (
                                    entity_id,
                                    attribute_set_id,
                                    type_id,
                                    sku,
                                    updated_at,
                                    has_options,
                                    store_product_id,
                                    sinch_product_id
                                )(SELECT
                                     pm.entity_id,
                                     $this->defaultAttributeSetId,
                                     'simple',
                                     a.product_sku,
                                     NOW(),
                                     0,
                                     a.store_product_id,
                                     a.sinch_product_id
                                  FROM " . $this->_getTableName('products_temp') . " a
                                  LEFT JOIN " . $this->_getTableName('sinch_products_mapping') . " pm
                                     ON a.store_product_id=pm.store_product_id
                                     AND a.sinch_product_id=pm.sinch_product_id
                                  WHERE pm.entity_id IS NULL
                                )
                                ON DUPLICATE KEY UPDATE
                                    sku= a.product_sku,
                                    store_product_id=a.store_product_id,
                                    sinch_product_id=a.sinch_product_id
                              ");

        //Set enabled
        $result = $this->_doQuery("DELETE cpei
                                FROM  " . $this->_getTableName('catalog_product_entity_int') . " cpei
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON cpei.entity_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
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
                                    FROM " . $this->_getTableName('catalog_product_entity') . " a
                                    INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                        ON a.store_product_id=w.store_product_id
                                 )
                                 ON DUPLICATE KEY UPDATE
                                    value=1
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(SELECT
                                    " . $this->_getProductAttributeId('status') . ",
                                    0,
                                    a.entity_id,
                                    1
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                )
                                ON DUPLICATE KEY UPDATE
                                    value=1
                              ");

        //Unifying products with categories.
        $result = $this->_doQuery("DELETE ccp
                                FROM " . $this->_getTableName('catalog_category_product') . " ccp
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON ccp.product_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("UPDATE IGNORE " . $this->_getTableName('catalog_category_product') . " ccp
                                LEFT JOIN " . $this->_getTableName('catalog_category_entity') . " cce
                                    ON ccp.category_id=cce.entity_id
                                SET ccp.category_id=" . $this->_rootCat . "
                                WHERE cce.entity_id IS NULL");

        $result = $this->_doQuery("DELETE ccp FROM " . $this->_getTableName('catalog_category_product') . " ccp
                                LEFT JOIN " . $this->_getTableName('catalog_category_entity') . " cce
                                    ON ccp.category_id=cce.entity_id
                                WHERE cce.entity_id IS NULL");

        $this->_doQuery(" DROP TABLE IF EXISTS " . $this->_getTableName('catalog_category_product') . "_for_delete_temp");
        // TEMPORARY
        $this->_doQuery("
                CREATE TABLE `" . $this->_getTableName('catalog_category_product') . "_for_delete_temp` (
                    `category_id` int(10) unsigned NOT NULL default '0',
                    `product_id` int(10) unsigned NOT NULL default '0',
                    `store_product_id` int(10) NOT NULL default '0',
                    `store_category_id` int(10) NOT NULL default '0',
                    `new_category_id` int(10) NOT NULL default '0',
                    UNIQUE KEY `UNQ_CATEGORY_PRODUCT` (`category_id`,`product_id`),
                    KEY `CATALOG_CATEGORY_PRODUCT_CATEGORY` (`category_id`),
                    KEY `CATALOG_CATEGORY_PRODUCT_PRODUCT` (`product_id`),
                    KEY `CATALOG_NEW_CATEGORY_PRODUCT_CATEGORY` (`new_category_id`)
                    )

                ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_category_product') . "_for_delete_temp (
                                    category_id,
                                    product_id,
                                    store_product_id
                                )(SELECT
                                    ccp.category_id,
                                    ccp.product_id,
                                    cpe.store_product_id
                                  FROM " . $this->_getTableName('catalog_category_product') . " ccp
                                  JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON ccp.product_id=cpe.entity_id
                                             WHERE store_product_id is not null
                                )
                              ");

        $result = $this->_doQuery("UPDATE " . $this->_getTableName('catalog_category_product') . "_for_delete_temp ccpfd
                                JOIN " . $this->_getTableName('products_temp') . " p
                                    ON ccpfd.store_product_id=p.store_product_id
                                SET ccpfd.store_category_id=p.store_category_id
                                WHERE ccpfd.store_product_id!=0
                              ");

        $result = $this->_doQuery("UPDATE " . $this->_getTableName('catalog_category_product') . "_for_delete_temp ccpfd
                                JOIN " . $this->_getTableName('sinch_categories_mapping') . " scm
                                    ON ccpfd.store_category_id=scm.store_category_id
                                SET ccpfd.new_category_id=scm.shop_entity_id
                                WHERE ccpfd.store_category_id!=0
                              ");

        $result = $this->_doQuery("DELETE FROM " . $this->_getTableName('catalog_category_product') . "_for_delete_temp
                                WHERE category_id=new_category_id");

        $result = $this->_doQuery("
                                DELETE ccp
                                FROM " . $this->_getTableName('catalog_category_product') . " ccp
                                JOIN " . $this->_getTableName('catalog_category_product') . "_for_delete_temp ccpfd
                                    ON ccp.product_id=ccpfd.product_id
                                    AND ccp.category_id=ccpfd.category_id
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_category_product') . " (
                                    category_id,
                                    product_id
                                )(SELECT
                                    scm.shop_entity_id,
                                    cpe.entity_id
                                  FROM " . $this->_getTableName('catalog_product_entity') . " cpe
                                  JOIN " . $this->_getTableName('products_temp') . " p
                                    ON cpe.store_product_id=p.store_product_id
                                  JOIN " . $this->_getTableName('sinch_categories_mapping') . " scm
                                    ON p.store_category_id=scm.store_category_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    product_id = cpe.entity_id
                              ");
        //add multi categories;
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_category_product') . "
                                (category_id,  product_id)
                                (SELECT
                                 scm.shop_entity_id,
                                 cpe.entity_id
                                 FROM " . $this->_getTableName('catalog_product_entity') . " cpe
                                 JOIN " . $this->_getTableName('products_temp') . " p
                                 ON cpe.store_product_id = p.store_product_id
                                 JOIN " . $this->_getTableName('sinch_product_categories') . " spc
                                 ON p.store_product_id=spc.store_product_id
                                 JOIN " . $this->_getTableName('sinch_categories_mapping') . " scm
                                 ON spc.store_category_id = scm.store_category_id
                                )
                                ON DUPLICATE KEY UPDATE
                                product_id = cpe.entity_id
                                ");
        //Indexing products and categories in the shop
        $result = $this->_doQuery("DELETE ccpi
                                FROM " . $this->_getTableName('catalog_category_product_index') . " ccpi
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON ccpi.product_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_category_product_index') . " (
                                    category_id,
                                    product_id,
                                    position,
                                    is_parent,
                                    store_id,
                                    visibility
                                )(
                                  SELECT
                                    a.category_id,
                                    a.product_id,
                                    a.position,
                                    1,
                                    b.store_id,
                                    4
                                  FROM " . $this->_getTableName('catalog_category_product') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " b
                                )
                                ON DUPLICATE KEY UPDATE
                                    visibility = 4
                              ");

        $result = $this->_doQuery("
                                INSERT ignore INTO " . $this->_getTableName('catalog_category_product_index') . " (
                                    category_id,
                                    product_id,
                                    position,
                                    is_parent,
                                    store_id,
                                    visibility
                                )(
                                  SELECT
                                    " . $this->_rootCat . ",
                                    a.product_id,
                                    a.position,
                                    1,
                                    b.store_id,
                                    4
                                  FROM " . $this->_getTableName('catalog_category_product') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " b
                                )
                                ON DUPLICATE KEY UPDATE
                                    visibility = 4
                              ");

        //Set product name for specific web sites
        $result = $this->_doQuery("DELETE cpev
                                FROM " . $this->_getTableName('catalog_product_entity_varchar') . " cpev
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                ON cpev.entity_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(SELECT
                                    " . $this->_getProductAttributeId('name') . ",
                                    w.website,
                                    a.entity_id,
                                    b.product_name
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id= b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.product_name
                              ");

        // product name for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.product_name
                              ");

        $this->dropHTMLentities($this->_getProductEntityTypeId(), $this->_getProductAttributeId('name'));
        $this->addDescriptions();
        $this->cleanProductDistributors();
        if (!$this->_ignore_product_contracts) {
            $this->cleanProductContracts();
        }
        if ($this->product_file_format == "NEW") {
            $this->addReviews();
            $this->addWeight();
            $this->addSearchCache();
            $this->addPdfUrl();
            $this->addShortDescriptions();
            $this->addProductDistributors();
            if (!$this->_ignore_product_contracts) {
                $this->addProductContracts();
            }
        }
        $this->addMetaDescriptions();
        $this->addEAN();
        $this->addSpecification();
        $this->addManufacturers();

        //Enabling product index.
        /*$result = $this->_doQuery("DELETE cpei
                                FROM " . $this->_getTableName('catalog_product_enabled_index') . " cpei
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON cpei.product_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_enabled_index') . " (
                                    product_id,
                                    store_id,
                                    visibility
                                )(
                                  SELECT
                                    a.entity_id,
                                    w.website,
                                    4
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    visibility = 4
                              ");
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_enabled_index') . " (
                                    product_id,
                                    store_id,
                                    visibility
                                )(
                                  SELECT
                                    a.entity_id,
                                    0,
                                    4
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    visibility = 4
                              ");*/
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                  ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                value = 4
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = 4
                              ");

        $result = $this->_doQuery("DELETE cpw
                                FROM " . $this->_getTableName('catalog_product_website') . " cpw
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON cpw.product_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_website') . " (
                                    product_id,
                                    website_id
                                )(
                                  SELECT a.entity_id, w.website_id
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                      ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    product_id=a.entity_id,
                                    website_id=w.website_id
                              ");

        //Adding tax class "Taxable Goods"
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                  ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = 2
                ");
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_int') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = 2
                              ");

        // Load url Image
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.main_image_url
                              ");
        // image for specific web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.main_image_url
                              ");
        // small_image for specific web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.medium_image_url
                                ");
        // small_image for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.medium_image_url
                              ");
        // thumbnail for specific web site
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.thumb_image_url
                              ");
        // thumbnail for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.thumb_image_url

                ");

        $this->addRelatedProducts();
    }

    private function _getProductDefaulAttributeSetId()
    {
        if (!$this->defaultAttributeSetId) {
            $sql = "
                SELECT entity_type_id, default_attribute_set_id
                FROM " . $this->_getTableName('eav_entity_type') . "
                WHERE entity_type_code = 'catalog_product'
                LIMIT 1
                ";
            $result = $this->_doQuery($sql)->fetch();
            $this->defaultAttributeSetId = $result['default_attribute_set_id'];
        }
        return $this->defaultAttributeSetId;
    }

    public function dropHTMLentities($entity_type_id, $attribute_id)
    {
        // product name for all web sites
        $results = $this->_doQuery("
                                SELECT value, entity_id
                                FROM " . $this->_getTableName('catalog_product_entity_varchar') . "
                                WHERE attribute_id=" . $attribute_id
        )->fetchAll();

        foreach ($results as $key => $result) {
            $value = $this->valid_char($result['value']);
            if ($value != '' and $value != $result['value']) {
                $this->_doQuery("UPDATE " . $this->_getTableName('catalog_product_entity_varchar') . "
                              SET value=" . $this->_connection->quote($value) . "
                              WHERE entity_id=" . $result['entity_id'] . "
                              AND attribute_id=" . $attribute_id);
            }
        }
    }

    public function valid_char($string)
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

    public function addDescriptions()
    {
        // product description for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('description') . ",
                                    w.website,
                                    a.entity_id,
                                    b.description
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.description
                              ");

        // product description for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('description') . ",
                                    0,
                                    a.entity_id,
                                    b.description
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.description
                              ");
    }

    public function cleanProductDistributors()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->_doQuery("UPDATE " . $this->_getTableName('catalog_product_entity_varchar') . "
                    SET value = ''
                    WHERE attribute_id=" . $this->_getProductAttributeId('supplier_' . $i));
        }
    }

    public function cleanProductContracts()
    {
        $this->_doQuery("UPDATE " . $this->_getTableName('catalog_product_entity_varchar') . "
                    SET value = ''
                    WHERE attribute_id=" . $this->_getProductAttributeId('contract_id'));
    }

    public function addReviews()
    {
        // product reviews  for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('reviews') . ",
                                    w.website,
                                    a.entity_id,
                                    b.Reviews
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.Reviews
                              ");

        // product Reviews for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('reviews') . ",
                                    0,
                                    a.entity_id,
                                    b.Reviews
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.Reviews
                              ");
    }

    public function addWeight()
    {
        // product weight for specific web site
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_decimal') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('weight') . ",
                                    w.website,
                                    a.entity_id,
                                    b.Weight
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.Weight
                              ");
        // product weight for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_decimal') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('weight') . ",
                                    0,
                                    a.entity_id,
                                    b.Weight
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.Weight
                              ");
    }

    public function addSearchCache()
    {
        // product search_cache for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('sinch_search_cache') . ",
                                    w.website,
                                    a.entity_id,
                                    b.search_cache
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.search_cache
                              ");

        // product search_cache for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('sinch_search_cache') . ",
                                    0,
                                    a.entity_id,
                                    b.search_cache
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.search_cache
                              ");
    }

    public function addPdfUrl()
    {
        // product PDF Url for all web sites
        $result = $this->_doQuery("
                                UPDATE " . $this->_getTableName('products_temp') . "
                                SET pdf_url = CONCAT(
                                                        '<a href=\"#\" onclick=\"popWin(',
                                                        \"'\",
                                                        pdf_url,
                                                        \"'\",
                                                        \", 'pdf', 'width=500,height=800,left=50,top=50, location=no,status=yes,scrollbars=yes,resizable=yes'); return false;\",
                                                        '\"',
                                                        '>',
                                                        pdf_url,
                                                        '</a>')
                                WHERE pdf_url != ''
        ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('pdf_url') . ",
                                    w.website,
                                    a.entity_id,
                                    b.pdf_url
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.pdf_url
                              ");
        // product  PDF url for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('pdf_url') . ",
                                    0,
                                    a.entity_id,
                                    b.pdf_url
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.pdf_url
                              ");

    }

    public function addShortDescriptions()
    {
        // product short description for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('short_description') . ",
                                    w.website,
                                    a.entity_id,
                                    b.product_short_description
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.product_short_description
                              ");
        // product short description for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('short_description') . ",
                                    0,
                                    a.entity_id,
                                    b.product_short_description
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.product_short_description
                              ");
    }

    public function addMetaDescriptions()
    {
        if ($this->product_file_format == "NEW") {
            // product meta description for all web sites
            $result = $this->_doQuery("
                                    INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                        attribute_id,
                                        store_id,
                                        entity_id,
                                        value
                                    )(
                                      SELECT
                                        " . $this->_getProductAttributeId('meta_description') . ",
                                        w.website,
                                        a.entity_id,
                                        b.product_short_description
                                      FROM " . $this->_getTableName('catalog_product_entity') . " a
                                      INNER JOIN " . $this->_getTableName('products_temp') . " b
                                        ON a.store_product_id = b.store_product_id
                                      INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                        ON a.store_product_id=w.store_product_id
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        value = b.product_short_description
                                  ");
            // product meta description for all web sites
            $result = $this->_doQuery("
                                    INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                        attribute_id,
                                        store_id,
                                        entity_id,
                                        value
                                    )(
                                      SELECT
                                        " . $this->_getProductAttributeId('meta_description') . ",
                                        0,
                                        a.entity_id,
                                        b.product_short_description
                                      FROM " . $this->_getTableName('catalog_product_entity') . " a
                                      INNER JOIN " . $this->_getTableName('products_temp') . " b
                                        ON a.store_product_id = b.store_product_id
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        value = b.product_short_description
                                  ");
        } else {
            // product meta description for all web sites
            $result = $this->_doQuery("
                                    INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                        attribute_id,
                                        store_id,
                                        entity_id,
                                        value
                                    )(
                                      SELECT
                                        " . $this->_getProductAttributeId('meta_description') . ",
                                        w.website,
                                        a.entity_id,
                                        b.Title
                                      FROM " . $this->_getTableName('catalog_product_entity') . " a
                                      INNER JOIN " . $this->_getTableName('products_temp') . " b
                                        ON a.store_product_id = b.store_product_id
                                      INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                        ON a.store_product_id=w.store_product_id
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        value = b.Title
                                  ");
            // product meta description for all web sites
            $result = $this->_doQuery("
                                    INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                        attribute_id,
                                        store_id,
                                        entity_id,
                                        value
                                    )(
                                      SELECT
                                        " . $this->_getProductAttributeId('meta_description') . ",
                                        0,
                                        a.entity_id,
                                        b.Title
                                      FROM " . $this->_getTableName('catalog_product_entity') . " a
                                      INNER JOIN " . $this->_getTableName('products_temp') . " b
                                        ON a.store_product_id = b.store_product_id
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        value = b.Title
                                  ");
        }
    }

    public function addProductDistributors()
    {
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_distributors_stock_and_price_temporary'));
        $this->_doQuery("CREATE TABLE IF NOT EXISTS " . $this->_getTableName('sinch_distributors_stock_and_price_temporary') . "
                      LIKE " . $this->_getTableName('sinch_distributors_stock_and_price'));
        $this->_doQuery("INSERT INTO " . $this->_getTableName('sinch_distributors_stock_and_price_temporary') . " SELECT * FROM " . $this->_getTableName('sinch_distributors_stock_and_price'));
        for ($i = 1; $i <= 5; $i++) {
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_distributors_stock_and_price_temporary_supplier'));
            $this->_doQuery("CREATE TABLE IF NOT EXISTS " . $this->_getTableName('sinch_distributors_stock_and_price_temporary_supplier') . "
                      LIKE " . $this->_getTableName('sinch_distributors_stock_and_price'));
            $this->_doQuery("INSERT INTO " . $this->_getTableName('sinch_distributors_stock_and_price_temporary_supplier') . " SELECT * FROM " . $this->_getTableName('sinch_distributors_stock_and_price_temporary') . " GROUP BY store_product_id");

            // product Distributors for all web sites
            $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('supplier_' . $i) . ",
                                    w.website,
                                    a.entity_id,
                                    d.distributor_name
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('sinch_distributors_stock_and_price_temporary_supplier') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('sinch_distributors') . " d
                                    ON b.distributor_id = d.distributor_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = d.distributor_name
                              ");
            // product Distributors for all web sites
            $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('supplier_' . $i) . ",
                                    0,
                                    a.entity_id,
                                    d.distributor_name
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('sinch_distributors_stock_and_price_temporary_supplier') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('sinch_distributors') . " d
                                    ON b.distributor_id = d.distributor_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = d.distributor_name
                              ");

            $this->_doQuery("DELETE sdsapt FROM " . $this->_getTableName('sinch_distributors_stock_and_price_temporary') . " sdsapt JOIN " . $this->_getTableName('sinch_distributors_stock_and_price_temporary_supplier') . " sdsapts ON sdsapt.store_product_id = sdsapts.store_product_id AND sdsapt.distributor_id = sdsapts.distributor_id");
        }

    }

    public function addProductContracts()
    {
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_product_contracts_temporary'));
        $this->_doQuery("CREATE TABLE IF NOT EXISTS " . $this->_getTableName('sinch_product_contracts_temporary') . "(
                          `store_product_id` int(11) DEFAULT NULL,
                          `contract_id_str` varchar(255) DEFAULT NULL,
                          KEY `store_product_id` (store_product_id)
            )
    ");
        $this->_doQuery("INSERT INTO " . $this->_getTableName('sinch_product_contracts_temporary') . " SELECT store_product_id, group_concat(contract_id) FROM " . $this->_getTableName('sinch_product_contracts') . " GROUP BY store_product_id");
        // product Distributors for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('contract_id') . ",
                                    w.website,
                                    a.entity_id,
                                    b.contract_id_str
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('sinch_product_contracts_temporary') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.contract_id_str
                              ");
        // product Distributors for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('contract_id') . ",
                                    0,
                                    a.entity_id,
                                    b.contract_id_str
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('sinch_product_contracts_temporary') . " b
                                    ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.contract_id_str
                              ");
    }

    public function addEAN()
    {
        //gather EAN codes for each product
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('EANs_temp'));
        $this->_doQuery("
                      CREATE TEMPORARY TABLE " . $this->_getTableName('EANs_temp') . " (
                        sinch_product_id int(11),
                        store_product_id int(11),
                        EANs text,
                        KEY `sinch_product_id` (`sinch_product_id`),
                        KEY `store_product_id` (`store_product_id`)
                     )
                ");
        $this->_doQuery("
                      INSERT INTO " . $this->_getTableName('EANs_temp') . " (
                            sinch_product_id,
                            EANs
                      )(SELECT
                            sec.product_id,
                        GROUP_CONCAT(DISTINCT ean_code ORDER BY ean_code DESC SEPARATOR ', ') AS eans
                        FROM " . $this->_getTableName('sinch_ean_codes') . " sec
                        GROUP BY sec.product_id
                      )
                    ");
        $this->_doQuery("UPDATE " . $this->_getTableName('EANs_temp') . " e
                          JOIN " . $this->_getTableName('products_temp') . " p
                            ON e.sinch_product_id=p.sinch_product_id
                          SET e.store_product_id=p.store_product_id");
        // product EANs for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('ean') . ",
                                    w.website,
                                    a.entity_id,
                                    e.EANs
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('EANs_temp') . " e
                                    ON a.store_product_id = e.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = e.EANs
                ");

        // product EANs for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('ean') . ",
                                    0,
                                    a.entity_id,
                                    e.EANs
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('EANs_temp') . " e
                                    ON a.store_product_id = e.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = e.EANs
                              ");
    }

    public function addSpecification()
    {
        // product specification for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('specification') . ",
                                    w.website,
                                    a.entity_id,
                                    b.specifications
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                    ON a.store_product_id = b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.specifications
                ");
        // product specification  for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_text') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('specification') . ",
                                    0,
                                    a.entity_id,
                                    b.specifications
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('products_temp') . " b
                                      ON a.store_product_id = b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.specifications
                              ");
    }

    public function addRelatedProducts()
    {
        $this->_doQuery("UPDATE " . $this->_getTableName('sinch_related_products') . " rpt
                      JOIN " . $this->_getTableName('products_temp') . " p
                        ON rpt.sinch_product_id=p.sinch_product_id
                      JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                        ON p.store_product_id=cpe.store_product_id
                      SET rpt.store_product_id=p.store_product_id, rpt.entity_id=cpe.entity_id");

        $this->_doQuery("UPDATE " . $this->_getTableName('sinch_related_products') . " rpt
                      JOIN " . $this->_getTableName('products_temp') . " p
                        ON rpt.related_sinch_product_id=p.sinch_product_id
                      JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                        ON p.store_product_id=cpe.store_product_id
                      SET rpt.store_related_product_id=p.store_product_id, rpt.related_entity_id=cpe.entity_id");

        $results = $this->_doQuery("SELECT
                                    link_type_id,
                                    code
                                FROM " . $this->_getTableName('catalog_product_link_type')
        )->fetchAll();

        $link_type = [];

        foreach ($results as $key => $res) {
            $link_type[$res['code']] = $res['link_type_id'];
        }

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_link') . " (
                                    product_id,
                                    linked_product_id,
                                    link_type_id
                                )(
                                  SELECT
                                    entity_id,
                                    related_entity_id,
                                    " . $link_type['relation'] . "
                                  FROM " . $this->_getTableName('sinch_related_products') . "
                                  WHERE store_product_id IS NOT NULL
                                  AND store_related_product_id IS NOT NULL
                                )
                                ON DUPLICATE KEY UPDATE
                                    product_id = entity_id,
                                    linked_product_id = related_entity_id
                ");
        $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('catalog_product_link_attribute_int') . "_tmp");

        $this->_doQuery("CREATE TEMPORARY TABLE " . $this->_getTableName('catalog_product_link_attribute_int') . "_tmp (
                        `value_id` int(11) default NULL,
                        `product_link_attribute_id` smallint(6) unsigned default NULL,
                        `link_id` int(11) unsigned default NULL,
                        `value` int(11) NOT NULL default '0',
                         KEY `FK_INT_PRODUCT_LINK_ATTRIBUTE` (`product_link_attribute_id`),
                         KEY `FK_INT_PRODUCT_LINK` (`link_id`)
                      )
                    ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_link_attribute_int') . "_tmp(
                                    product_link_attribute_id,
                                    link_id,
                                    value
                                )(
                                  SELECT
                                    2,
                                    cpl.link_id,
                                    0
                                  FROM " . $this->_getTableName('catalog_product_link') . " cpl
                                )
                              ");

        $result = $this->_doQuery("UPDATE " . $this->_getTableName('catalog_product_link_attribute_int') . "_tmp ct
                                JOIN " . $this->_getTableName('catalog_product_link_attribute_int') . " c
                                    ON ct.link_id=c.link_id
                                SET ct.value_id=c.value_id
                                WHERE c.product_link_attribute_id=2
                              ");

        $result = $this->_doQuery("
                                    INSERT INTO " . $this->_getTableName('catalog_product_link_attribute_int') . " (
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
                                      FROM " . $this->_getTableName('catalog_product_link_attribute_int') . "_tmp ct
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        link_id=ct.link_id

                                  ");
    }

    public function replaceMagentoProductsMultistore($coincidence)
    {
        echo("\nReplace Magento Products Multistore 1...\n");

        $products_temp = $this->_getTableName('products_temp');
        $products_website_temp = $this->_getTableName('products_website_temp');
        $catalog_product_entity = $this->_getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->_getTableName('catalog_product_entity_int');
        $catalog_product_entity_varchar = $this->_getTableName('catalog_product_entity_varchar');
        $catalog_category_product = $this->_getTableName('catalog_category_product');
        $sinch_products_mapping = $this->_getTableName('sinch_products_mapping');
        $catalog_category_entity = $this->_getTableName('catalog_category_entity');
        $sinch_categories_mapping = $this->_getTableName('sinch_categories_mapping');
        $catalog_category_product_index = $this->_getTableName('catalog_category_product_index');
        $core_store = $this->_getTableName('store');
        $catalog_product_enabled_index = $this->_getTableName('catalog_product_enabled_index');
        $catalog_product_website = $this->_getTableName('catalog_product_website');
        $catalog_category_entity_varchar = $this->_getTableName('catalog_category_entity_varchar');

        $_getProductEntityTypeId = $this->_getProductEntityTypeId();
        $_defaultAttributeSetId = $this->_getProductDefaulAttributeSetId();

        $attr_atatus = $this->_getProductAttributeId('status');
        $attr_name = $this->_getProductAttributeId('name');
        $attr_visibility = $this->_getProductAttributeId('visibility');
        $attr_tax_class_id = $this->_getProductAttributeId('tax_class_id');
        $attr_image = $this->_getProductAttributeId('image');
        $attr_small_image = $this->_getProductAttributeId('small_image');
        $attr_thumbnail = $this->_getProductAttributeId('thumbnail');

        $cat_attr_name = $this->_getCategoryAttributeId('name');
        echo("\nReplace Magento Products Multistore 2\...n");
        //clear products, inserting new products and updating old others.
        $query = "
            DELETE cpe
            FROM $catalog_product_entity cpe
            JOIN $sinch_products_mapping pm
                ON cpe.entity_id = pm.entity_id
            WHERE pm.shop_store_product_id IS NOT NULL
                AND pm.store_product_id IS NULL";
        $result = $this->_doQuery($query);
        echo("\nReplace Magento Products Multistore 3\...n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity
                (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, store_product_id, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                a.product_sku,
                NOW(),
                0,
                a.store_product_id,
                a.sinch_product_id
            FROM $products_temp a
            LEFT JOIN $sinch_products_mapping pm
                ON a.store_product_id = pm.store_product_id
                AND a.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = a.product_sku,
                store_product_id = a.store_product_id,
                sinch_product_id = a.sinch_product_id");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity
                (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, store_product_id, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                a.product_sku,
                NOW(),
                0,
                a.store_product_id,
                a.sinch_product_id
            FROM $products_temp a
            LEFT JOIN $sinch_products_mapping pm
                ON a.store_product_id = pm.store_product_id
                AND a.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = a.product_sku,
                store_product_id = a.store_product_id,
                sinch_product_id = a.sinch_product_id");

        echo("\nReplace Magento Products Multistore 4\...n");
        //Set enabled
        $result = $this->_doQuery("
            DELETE cpei
            FROM $catalog_product_entity_int cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                w.website,
                a.entity_id,
                1
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 1");
        echo("\nReplace Magento Products Multistore 5\...n");
        // set status = 1 for all stores
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                0,
                a.entity_id,
                1
            FROM $catalog_product_entity a
            )
            ON DUPLICATE KEY UPDATE
                value = 1");
        echo("\nReplace Magento Products Multistore 6\...n");
        //Unifying products with categories.
        $result = $this->_doQuery("
            DELETE ccp
            FROM $catalog_category_product ccp
            LEFT JOIN $catalog_product_entity cpe
                ON ccp.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");
        echo("\nReplace Magento Products Multistore 7\...n");
        $rootCats = $this->_getTableName('rootCats');
        $result = $this->_doQuery("DROP TABLE IF EXISTS $rootCats");
        $result = $this->_doQuery("
CREATE TABLE $rootCats
SELECT
    entity_id,
    path,
    SUBSTRING(path, LOCATE('/', path)+1) AS short_path,
    LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1)) AS end_pos,
    SUBSTRING(SUBSTRING(path, LOCATE('/', path)+1), 1, LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1))-1) as rootCat
FROM $catalog_category_entity
");
        $result = $this->_doQuery("UPDATE $rootCats SET rootCat = entity_id WHERE CHAR_LENGTH(rootCat) = 0");
        echo("\nReplace Magento Products Multistore 8\...n");

        $result = $this->_doQuery("
            UPDATE IGNORE $catalog_category_product ccp
            LEFT JOIN $catalog_category_entity cce
                ON ccp.category_id = cce.entity_id
            JOIN $rootCats rc
                ON cce.entity_id = rc.entity_id
            SET ccp.category_id = rc.rootCat
            WHERE cce.entity_id IS NULL");
        echo("\nReplace Magento Products Multistore 9\...n");
        $result = $this->_doQuery("
            DELETE ccp
            FROM $catalog_category_product ccp
            LEFT JOIN $catalog_category_entity cce
                ON ccp.category_id = cce.entity_id
            WHERE cce.entity_id IS NULL");
        echo("\nReplace Magento Products Multistore 10...\n");
        $catalog_category_product_for_delete_temp = $catalog_category_product . "_for_delete_temp";

        // TEMPORARY
        $this->_doQuery(" DROP TABLE IF EXISTS $catalog_category_product_for_delete_temp");
        $this->_doQuery("
            CREATE TABLE $catalog_category_product_for_delete_temp
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
            )");

        echo("\nReplace Magento Products Multistore 11...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_category_product_for_delete_temp
                (category_id, product_id, store_product_id)
            (SELECT
                ccp.category_id,
                ccp.product_id,
                cpe.store_product_id
            FROM $catalog_category_product ccp
            JOIN $catalog_product_entity cpe
                ON ccp.product_id = cpe.entity_id
            WHERE store_product_id IS NOT NULL)");

        echo("\nReplace Magento Products Multistore 12...\n");

        $result = $this->_doQuery("
            UPDATE $catalog_category_product_for_delete_temp ccpfd
            JOIN $products_temp p
                ON ccpfd.store_product_id = p.store_product_id
            SET ccpfd.store_category_id = p.store_category_id
            WHERE ccpfd.store_product_id != 0");

        echo("\nReplace Magento Products Multistore 13...\n");

        $result = $this->_doQuery("
            UPDATE $catalog_category_product_for_delete_temp ccpfd
            JOIN $sinch_categories_mapping scm
                ON ccpfd.store_category_id = scm.store_category_id
            SET ccpfd.new_category_id = scm.shop_entity_id
            WHERE ccpfd.store_category_id != 0");

        echo("\nReplace Magento Products Multistore 14...\n");

        $result = $this->_doQuery("DELETE FROM $catalog_category_product_for_delete_temp WHERE category_id = new_category_id");
        $result = $this->_doQuery("
            DELETE ccp
            FROM $catalog_category_product ccp
            JOIN $catalog_category_product_for_delete_temp ccpfd
                ON ccp.product_id = ccpfd.product_id
                AND ccp.category_id = ccpfd.category_id");
        echo("\nReplace Magento Products Multistore 15...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_category_product
                (category_id,  product_id)
            (SELECT
                scm.shop_entity_id,
                cpe.entity_id
            FROM $catalog_product_entity cpe
            JOIN $products_temp p
                ON cpe.store_product_id = p.store_product_id
            JOIN $sinch_categories_mapping scm
                ON p.store_category_id = scm.store_category_id
            )
            ON DUPLICATE KEY UPDATE
                product_id = cpe.entity_id");
        echo("\nReplace Magento Products Multistore 15....1 (add multi categories)\n");

        $result = $this->_doQuery("
        INSERT INTO $catalog_category_product
        (category_id,  product_id)
        (SELECT
         scm.shop_entity_id,
         cpe.entity_id
         FROM $catalog_product_entity cpe
         JOIN $products_temp p
         ON cpe.store_product_id = p.store_product_id
         JOIN " . $this->_getTableName('sinch_product_categories') . " spc
         ON p.store_product_id=spc.store_product_id
         JOIN $sinch_categories_mapping scm
         ON spc.store_category_id = scm.store_category_id
        )
        ON DUPLICATE KEY UPDATE
        product_id = cpe.entity_id
        ");
        echo("\nReplace Magento Products Multistore 16...\n");

        //Indexing products and categories in the shop
        $result = $this->_doQuery("
            DELETE ccpi
            FROM $catalog_category_product_index ccpi
            LEFT JOIN $catalog_product_entity cpe
                ON ccpi.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");
        echo("\nReplace Magento Products Multistore 16....2\n");
        $result = $this->_doQuery("
            INSERT INTO $catalog_category_product_index
                (category_id, product_id, position, is_parent, store_id, visibility)
            (SELECT
                a.category_id,
                a.product_id,
                a.position,
                1,
                b.store_id,
                4
            FROM $catalog_category_product a
            JOIN $core_store b
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");
        echo("\nReplace Magento Products Multistore 17...\n");

        $result = $this->_doQuery("
            INSERT ignore INTO $catalog_category_product_index
                (category_id, product_id, position, is_parent, store_id, visibility)
            (SELECT
                rc.rootCat,
                a.product_id,
                a.position,
                1,
                b.store_id,
                4
            FROM $catalog_category_product a
            JOIN $rootCats rc
                ON a.category_id = rc.entity_id
            JOIN $core_store b
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");

        echo("\nReplace Magento Products Multistore 18...\n");
        //Set product name for specific web sites
        $result = $this->_doQuery("
            DELETE cpev
            FROM $catalog_product_entity_varchar cpev
            LEFT JOIN $catalog_product_entity cpe
                ON cpev.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                w.website,
                a.entity_id,
                b.product_name
            FROM $catalog_product_entity a
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.product_name");

        echo("\nReplace Magento Products Multistore 19...\n");
        // product name for all web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                0,
                a.entity_id,
                b.product_name
            FROM $catalog_product_entity a
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.product_name");

        echo("\nReplace Magento Products Multistore 20...\n");
        $this->dropHTMLentities($this->_getProductEntityTypeId(), $this->_getProductAttributeId('name'));
        $this->addDescriptions();
        $this->cleanProductDistributors();
        if ($this->product_file_format == "NEW") {
            $this->addReviews();
            $this->addWeight();
            $this->addSearchCache();
            $this->addPdfUrl();
            $this->addShortDescriptions();
            $this->addProductDistributors();
        }
        $this->addMetaDescriptions();
        $this->addEAN();
        $this->addSpecification();
        $this->addManufacturers();

        echo("\nReplace Magento Products Multistore 21...\n");

        //Enabling product index.
        /*$result = $this->_doQuery("
            DELETE cpei
            FROM $catalog_product_enabled_index cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        echo("\nReplace Magento Products Multistore 22...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_enabled_index
                (product_id, store_id, visibility)
            (SELECT
                a.entity_id,
                w.website,
                4
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");

        echo("\nReplace Magento Products Multistore 23...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_enabled_index
                (product_id, store_id, visibility)
            (SELECT
                a.entity_id,
                0,
                4
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");*/

        echo("\nReplace Magento Products Multistore 24...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                w.website,
                a.entity_id,
                4
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 4");

        echo("\nReplace Magento Products Multistore 25...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                ( attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                0,
                a.entity_id,
                4
            FROM $catalog_product_entity a
            )
            ON DUPLICATE KEY UPDATE
                value = 4");

        echo("\nReplace Magento Products Multistore 26...\n");

        $result = $this->_doQuery("
            DELETE cpw
            FROM $catalog_product_website cpw
            LEFT JOIN $catalog_product_entity cpe
                ON cpw.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        echo("\nReplace Magento Products Multistore 27...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_website
                (product_id, website_id)
            (SELECT
                a.entity_id,
                w.website_id
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                product_id = a.entity_id,
                website_id = w.website_id");

        echo("\nReplace Magento Products Multistore 28...\n");

        //Adding tax class "Taxable Goods"
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                w.website,
                a.entity_id,
                2
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 2");

        echo("\nReplace Magento Products Multistore 29...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                0,
                a.entity_id,
                2
            FROM $catalog_product_entity a
            )
            ON DUPLICATE KEY UPDATE
                value = 2");

        echo("\nReplace Magento Products Multistore 30...\n");

        // Load url Image
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                w.store_id,
                a.entity_id,
                b.main_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.main_image_url");

        echo("\nReplace Magento Products Multistore 31...\n");

        // image for specific web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                0,
                a.entity_id,
                b.main_image_url
            FROM $catalog_product_entity a
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.main_image_url");

        echo("\nReplace Magento Products Multistore 32...\n");

        // small_image for specific web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                w.store_id,
                a.entity_id,
                b.medium_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.medium_image_url");

        echo("\nReplace Magento Products Multistore 33...\n");

        // small_image for all web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                ( attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                0,
                a.entity_id,
                b.medium_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.medium_image_url");

        echo("\nReplace Magento Products Multistore 34...\n");

        // thumbnail for specific web site
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                w.store_id,
                a.entity_id,
                b.thumb_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.thumb_image_url");

        echo("\nReplace Magento Products Multistore 35...\n");

        // thumbnail for all web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                0,
                a.entity_id,
                b.thumb_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.thumb_image_url");

        echo("\nReplace Magento Products Multistore 36...\n");

        $this->addRelatedProducts();
        echo("\nReplace Magento Products Multistore 41...\n");
    }

    public function replaceMagentoProductsMultistoreMERGE($coincidence)
    {
        echo("\nReplace Magento Products Multistore MERGE 1...\n");

        $products_temp = $this->_getTableName('products_temp');
        $products_website_temp = $this->_getTableName('products_website_temp');
        $catalog_product_entity = $this->_getTableName('catalog_product_entity');
        $catalog_product_entity_int = $this->_getTableName('catalog_product_entity_int');
        $catalog_product_entity_varchar = $this->_getTableName('catalog_product_entity_varchar');
        $catalog_category_product = $this->_getTableName('catalog_category_product');
        $sinch_products_mapping = $this->_getTableName('sinch_products_mapping');
        $sinch_products = $this->_getTableName('sinch_products');
        $catalog_category_entity = $this->_getTableName('catalog_category_entity');
        $sinch_categories_mapping = $this->_getTableName('sinch_categories_mapping');
        $catalog_category_product_index = $this->_getTableName('catalog_category_product_index');
        $core_store = $this->_getTableName('store');
        $catalog_product_enabled_index = $this->_getTableName('catalog_product_enabled_index');
        $catalog_product_website = $this->_getTableName('catalog_product_website');
        $catalog_category_entity_varchar = $this->_getTableName('catalog_category_entity_varchar');

        $_getProductEntityTypeId = $this->_getProductEntityTypeId();
        $_defaultAttributeSetId = $this->_getProductDefaulAttributeSetId();

        $attr_atatus = $this->_getProductAttributeId('status');
        $attr_name = $this->_getProductAttributeId('name');
        $attr_visibility = $this->_getProductAttributeId('visibility');
        $attr_tax_class_id = $this->_getProductAttributeId('tax_class_id');
        $attr_image = $this->_getProductAttributeId('image');
        $attr_small_image = $this->_getProductAttributeId('small_image');
        $attr_thumbnail = $this->_getProductAttributeId('thumbnail');

        $cat_attr_name = $this->_getCategoryAttributeId('name');
        echo("\nReplace Magento Products Multistore MERGE 2...\n");
        //clear products, inserting new products and updating old others.
        $query = "
            DELETE cpe
            FROM $catalog_product_entity cpe
            JOIN $sinch_products_mapping pm
                ON cpe.entity_id = pm.entity_id
            WHERE pm.shop_store_product_id IS NOT NULL
                AND pm.store_product_id IS NULL";
        $result = $this->_doQuery($query);
        echo("\nReplace Magento Products Multistore MERGE 3...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity
                (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, store_product_id, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                a.product_sku,
                NOW(),
                0,
                a.store_product_id,
                a.sinch_product_id
            FROM $products_temp a
            LEFT JOIN $sinch_products_mapping pm
                ON a.store_product_id = pm.store_product_id
                AND a.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NOT NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = a.product_sku,
                store_product_id = a.store_product_id,
                sinch_product_id = a.sinch_product_id");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity
                (entity_id, attribute_set_id, type_id, sku, updated_at, has_options, store_product_id, sinch_product_id)
            (SELECT
                pm.entity_id,
                $_defaultAttributeSetId,
                'simple',
                a.product_sku,
                NOW(),
                0,
                a.store_product_id,
                a.sinch_product_id
            FROM $products_temp a
            LEFT JOIN $sinch_products_mapping pm
                ON a.store_product_id = pm.store_product_id
                AND a.sinch_product_id = pm.sinch_product_id
            WHERE pm.entity_id IS NULL
            )
            ON DUPLICATE KEY UPDATE
                sku = a.product_sku,
                store_product_id = a.store_product_id,
                sinch_product_id = a.sinch_product_id");

        echo("\nReplace Magento Products Multistore MERGE 4...\n");
        //Set enabled
        $result = $this->_doQuery("
            DELETE cpei
            FROM $catalog_product_entity_int cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                w.website,
                a.entity_id,
                1
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 1");
        echo("\nReplace Magento Products Multistore MERGE 5...\n");
        // set status = 1 for all stores
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_atatus,
                0,
                a.entity_id,
                1
            FROM $catalog_product_entity a
            )
            ON DUPLICATE KEY UPDATE
                value = 1");
        echo("\nReplace Magento Products Multistore MERGE 6...\n");
        //Unifying products with categories.
        $result = $this->_doQuery("
            DELETE ccp
            FROM $catalog_category_product ccp
            LEFT JOIN $catalog_product_entity cpe
                ON ccp.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        echo("\nReplace Magento Products Multistore MERGE 7...\n");

        $rootCats = $this->_getTableName('rootCats');

        $result = $this->_doQuery("DROP TABLE IF EXISTS $rootCats");
        $result = $this->_doQuery("
CREATE TABLE $rootCats
SELECT
    entity_id,
    path,
    SUBSTRING(path, LOCATE('/', path)+1) AS short_path,
    LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1)) AS end_pos,
    SUBSTRING(SUBSTRING(path, LOCATE('/', path)+1), 1, LOCATE('/', SUBSTRING(path, LOCATE('/', path)+1))-1) as rootCat
FROM $catalog_category_entity
");
        $result = $this->_doQuery("UPDATE $rootCats SET rootCat = entity_id WHERE CHAR_LENGTH(rootCat) = 0");

        echo("\nReplace Magento Products Multistore MERGE 8...\n");

        $result = $this->_doQuery("
            UPDATE IGNORE $catalog_category_product ccp
            LEFT JOIN $catalog_category_entity cce
                ON ccp.category_id = cce.entity_id
            JOIN $rootCats rc
                ON cce.entity_id = rc.entity_id
            SET ccp.category_id = rc.rootCat
            WHERE cce.entity_id IS NULL");

        echo("\nReplace Magento Products Multistore MERGE 9...\n");

        $result = $this->_doQuery("
            DELETE ccp
            FROM $catalog_category_product ccp
            LEFT JOIN $catalog_category_entity cce
                ON ccp.category_id = cce.entity_id
            WHERE cce.entity_id IS NULL");

        $stinch_products_delete = $this->_getTableName('stinch_products_delete');

        $result = $this->_doQuery("DROP TABLE IF EXISTS $stinch_products_delete");
        $result = $this->_doQuery("
CREATE TABLE $stinch_products_delete
SELECT cpe.entity_id
FROM $catalog_product_entity cpe
WHERE cpe.entity_id NOT IN
(
SELECT cpe2.entity_id
FROM $catalog_product_entity cpe2
JOIN $sinch_products sp
    ON cpe2.sinch_product_id = sp.sinch_product_id
)");
        $result = $this->_doQuery("DELETE cpe FROM $catalog_product_entity cpe JOIN $stinch_products_delete spd USING(entity_id)");

        $result = $this->_doQuery("DROP TABLE IF EXISTS $stinch_products_delete");

        echo("\nReplace Magento Products Multistore MERGE 15...\n");
        $result = $this->_doQuery("
            INSERT INTO $catalog_category_product
                (category_id,  product_id)
            (SELECT
                scm.shop_entity_id,
                cpe.entity_id
            FROM $catalog_product_entity cpe
            JOIN $products_temp p
                ON cpe.store_product_id = p.store_product_id
            JOIN $sinch_categories_mapping scm
                ON p.store_category_id = scm.store_category_id
            )
            ON DUPLICATE KEY UPDATE
                product_id = cpe.entity_id");
        echo("\nReplace Magento Products Multistore MERGE 15.1... (add multi categories)\n");
        $result = $this->_doQuery("
        INSERT INTO $catalog_category_product
        (category_id,  product_id)
        (SELECT
         scm.shop_entity_id,
         cpe.entity_id
         FROM $catalog_product_entity cpe
         JOIN $products_temp p
         ON cpe.store_product_id = p.store_product_id
         JOIN " . $this->_getTableName('sinch_product_categories') . " spc
         ON p.store_product_id=spc.store_product_id
         JOIN $sinch_categories_mapping scm
         ON spc.store_category_id = scm.store_category_id
        )
        ON DUPLICATE KEY UPDATE
        product_id = cpe.entity_id
        ");
        echo("\nReplace Magento Products Multistore MERGE 16...\n");

        //Indexing products and categories in the shop
        $result = $this->_doQuery("
            DELETE ccpi
            FROM $catalog_category_product_index ccpi
            LEFT JOIN $catalog_product_entity cpe
                ON ccpi.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");
        echo("\nReplace Magento Products Multistore MERGE 16....2\n");
        $result = $this->_doQuery("
            INSERT INTO $catalog_category_product_index
                (category_id, product_id, position, is_parent, store_id, visibility)
            (SELECT
                a.category_id,
                a.product_id,
                a.position,
                1,
                b.store_id,
                4
            FROM $catalog_category_product a
            JOIN $core_store b
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");
        echo("\nReplace Magento Products Multistore MERGE 17...\n");
        $rootCats = $this->_getTableName('rootCats');

        $result = $this->_doQuery("
            INSERT ignore INTO $catalog_category_product_index
                (category_id, product_id, position, is_parent, store_id, visibility)
            (SELECT
                rc.rootCat,
                a.product_id,
                a.position,
                1,
                b.store_id,
                4
            FROM $catalog_category_product a
            JOIN $rootCats rc
                ON a.category_id = rc.entity_id
            JOIN $core_store b
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");

        echo("\nReplace Magento Products Multistore MERGE 18...\n");
        //Set product name for specific web sites
        $result = $this->_doQuery("
            DELETE cpev
            FROM $catalog_product_entity_varchar cpev
            LEFT JOIN $catalog_product_entity cpe
                ON cpev.entity_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                w.website,
                a.entity_id,
                b.product_name
            FROM $catalog_product_entity a
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.product_name");

        echo("\nReplace Magento Products Multistore MERGE 19...\n");
        // product name for all web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_name,
                0,
                a.entity_id,
                b.product_name
            FROM $catalog_product_entity a
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.product_name");

        echo("\nReplace Magento Products Multistore MERGE 20...\n");
        $this->dropHTMLentities($this->_getProductEntityTypeId(), $this->_getProductAttributeId('name'));
        $this->addDescriptions();
        $this->cleanProductDistributors();
        if (!$this->_ignore_product_contracts) {
            $this->cleanProductContracts();
        }
        if ($this->product_file_format == "NEW") {
            $this->addReviews();
            $this->addWeight();
            $this->addSearchCache();
            $this->addPdfUrl();
            $this->addShortDescriptions();
            $this->addProductDistributors();
            if (!$this->_ignore_product_contracts) {
                $this->addProductContracts();
            }
        }
        $this->addMetaDescriptions();
        $this->addEAN();
        $this->addSpecification();
        $this->addManufacturers();
        echo("\nReplace Magento Products Multistore MERGE 21...\n");

        //Enabling product index.
        /*$result = $this->_doQuery("
            DELETE cpei
            FROM $catalog_product_enabled_index cpei
            LEFT JOIN $catalog_product_entity cpe
                ON cpei.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");
        echo("\nReplace Magento Products Multistore MERGE 22...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_enabled_index
                (product_id, store_id, visibility)
            (SELECT
                a.entity_id,
                w.website,
                4
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");

        echo("\nReplace Magento Products Multistore MERGE 23...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_enabled_index
                (product_id, store_id, visibility)
            (SELECT
                a.entity_id,
                0,
                4
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                visibility = 4");*/
        echo("\nReplace Magento Products Multistore MERGE 24...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                w.website,
                a.entity_id,
                4
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 4");

        echo("\nReplace Magento Products Multistore MERGE 25...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                ( attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_visibility,
                0,
                a.entity_id,
                4
            FROM $catalog_product_entity a
            )
            ON DUPLICATE KEY UPDATE
                value = 4");

        echo("\nReplace Magento Products Multistore MERGE 26...\n");
        $result = $this->_doQuery("
            DELETE cpw
            FROM $catalog_product_website cpw
            LEFT JOIN $catalog_product_entity cpe
                ON cpw.product_id = cpe.entity_id
            WHERE cpe.entity_id IS NULL");

        echo("\nReplace Magento Products Multistore MERGE 27...\n");
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_website
                (product_id, website_id)
            (SELECT
                a.entity_id,
                w.website_id
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                product_id = a.entity_id,
                website_id = w.website_id");

        echo("\nReplace Magento Products Multistore MERGE 28...\n");

        //Adding tax class "Taxable Goods"
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                w.website,
                a.entity_id,
                2
            FROM $catalog_product_entity a
            JOIN $products_website_temp w
                ON a.store_product_id = w.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = 2");

        echo("\nReplace Magento Products Multistore MERGE 29...\n");

        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_int
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_tax_class_id,
                0,
                a.entity_id,
                2
            FROM $catalog_product_entity a
            )
            ON DUPLICATE KEY UPDATE
                value = 2");

        echo("\nReplace Magento Products Multistore MERGE 30...\n");

        // Load url Image
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                w.store_id,
                a.entity_id,
                b.main_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.main_image_url");

        echo("\nReplace Magento Products Multistore MERGE 31...\n");

        // image for specific web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_image,
                0,
                a.entity_id,
                b.main_image_url
            FROM $catalog_product_entity a
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.main_image_url");

        echo("\nReplace Magento Products Multistore MERGE 32...\n");

        // small_image for specific web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                w.store_id,
                a.entity_id,
                b.medium_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.medium_image_url");

        echo("\nReplace Magento Products Multistore MERGE 33...\n");

        // small_image for all web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                ( attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_small_image,
                0,
                a.entity_id,
                b.medium_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.medium_image_url");

        echo("\nReplace Magento Products Multistore MERGE 34...\n");

        // thumbnail for specific web site
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                w.store_id,
                a.entity_id,
                b.thumb_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.thumb_image_url");

        echo("\nReplace Magento Products Multistore MERGE 35...\n");

        // thumbnail for all web sites
        $result = $this->_doQuery("
            INSERT INTO $catalog_product_entity_varchar
                (attribute_id, store_id, entity_id, value)
            (SELECT
                $attr_thumbnail,
                0,
                a.entity_id,
                b.thumb_image_url
            FROM $catalog_product_entity a
            JOIN $core_store w
            JOIN $products_temp b
                ON a.store_product_id = b.store_product_id
            )
            ON DUPLICATE KEY UPDATE
                value = b.thumb_image_url");

        echo("\nReplace Magento Products Multistore MERGE 36...\n");

        $this->addRelatedProducts();
        echo("\nReplace Magento Products Multistore MERGE 41...\n");
    }

    public function parseProductsPicturesGallery()
    {

        $parseFile = $this->varDir . FILE_PRODUCTS_PICTURES_GALLERY;
        if (filesize($parseFile)) {
            $this->_logImportInfo("Start parse " . FILE_PRODUCTS_PICTURES_GALLERY);
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('products_pictures_gallery_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('products_pictures_gallery_temp') . " (
                sinch_product_id int(11),
                                 image_url varchar(255),
                                 thumb_image_url varchar(255),
                                 store_product_id int(11),
                                 key(sinch_product_id),
                                 key(store_product_id)
                                     )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                                                   INTO TABLE " . $this->_getTableName('products_pictures_gallery_temp') . "
                                                   FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                                                   OPTIONALLY ENCLOSED BY '\"'
                                                   LINES TERMINATED BY \"\r\n\"
                                                   IGNORE 1 LINES ");

            $this->_doQuery("UPDATE " . $this->_getTableName('products_pictures_gallery_temp') . " ppgt
                          JOIN " . $this->_getTableName('sinch_products') . " sp
                            ON ppgt.sinch_product_id=sp.sinch_product_id
                          JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                            ON sp.store_product_id=cpe.store_product_id
                          SET ppgt.store_product_id=sp.store_product_id");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_products_pictures_gallery'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('products_pictures_gallery_temp') . "
                          TO " . $this->_getTableName('sinch_products_pictures_gallery'));

            $this->_logImportInfo("Finish parse" . FILE_PRODUCTS_PICTURES_GALLERY);
        } else {
            $this->_logImportInfo("Wrong file" . $parseFile);
        }
        $this->_logImportInfo(" ");

    }

    public function parseRestrictedValues()
    {

        $parseFile = $this->varDir . FILE_RESTRICTED_VALUES;
        if (filesize($parseFile) || $this->_ignore_restricted_values) {
            $this->_logImportInfo("Start parse " . FILE_RESTRICTED_VALUES);
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('restricted_values_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('restricted_values_temp') . " (
                            restricted_value_id int(11),
                            category_feature_id int(11),
                            text text,
                            display_order_number int(11),
                            KEY(restricted_value_id),
                            KEY(category_feature_id)
                          )");
            if (!$this->_ignore_restricted_values) {
                $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->_getTableName('restricted_values_temp') . "
                              FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES ");
            }
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_restricted_values'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('restricted_values_temp') . "
                          TO " . $this->_getTableName('sinch_restricted_values'));

            $this->_logImportInfo("Finish parse " . FILE_RESTRICTED_VALUES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(" ");
    }

    public function parseStockAndPrices()
    {
        $parseFile = $this->varDir . FILE_STOCK_AND_PRICES;

        if (filesize($parseFile)) {
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('stock_and_prices_temp_before'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('stock_and_prices_temp_before') . " (
                                 store_product_id int(11),
                                 stock int(11),
                                 price varchar(255),
                                 cost varchar(255),
                                 distributor_id int(11),
                                 KEY(store_product_id),
                                 KEY(distributor_id)
                          )");

            $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                          INTO TABLE " . $this->_getTableName('stock_and_prices_temp_before') . "
                          FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                          OPTIONALLY ENCLOSED BY '\"'
                          LINES TERMINATED BY \"\r\n\"
                          IGNORE 1 LINES ");

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('stock_and_prices_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('stock_and_prices_temp') . " (
                                 store_product_id int(11),
                                 stock int(11),
                                 price decimal(15,4),
                                 cost decimal(15,4),
                                 distributor_id int(11),
                                 KEY(store_product_id),
                                 KEY(distributor_id)
                          )");

            $this->_doQuery("
                        INSERT INTO " . $this->_getTableName('stock_and_prices_temp') . " (
                             store_product_id,
                             stock,
                             price,
                             cost,
                             distributor_id
                        )(
                          SELECT
                             store_product_id,
                             stock,
                             REPLACE(price, ',', '.'),
                             REPLACE(cost, ',', '.' ),
                             distributor_id
                          FROM " . $this->_getTableName('stock_and_prices_temp_before') . "
                        )
                      ");
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('stock_and_prices_temp_before'));

            $this->replaceMagentoProductsStockPrice();

            $res = $this->_doQuery("SELECT count(*) as cnt
                                 FROM " . $this->_getTableName('catalog_product_entity') . " a
                                 INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                    ON a.store_product_id=b.store_product_id")->fetch();
            $this->_doQuery("UPDATE " . $this->import_status_statistic_table . "
                          SET number_of_products=" . $res['cnt'] . "
                          WHERE id=" . $this->current_import_status_statistic_id);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_stock_and_prices'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('stock_and_prices_temp') . "
                          TO " . $this->_getTableName('sinch_stock_and_prices'));

            $this->_logImportInfo("Finish parse" . FILE_RELATED_PRODUCTS);
        } else {
            $this->_logImportInfo("Wrong file" . $parseFile);
        }
        $this->_logImportInfo(" ");
    }

    public function replaceMagentoProductsStockPrice()
    {
        //Add stock
        $result = $this->_doQuery("DELETE csi
                                FROM " . $this->_getTableName('cataloginventory_stock_item') . " csi
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON csi.product_id=cpe.entity_id
                                WHERE cpe.entity_id is null OR csi.website_id=0");

        //set all sinch products stock=0 before upgrade (nedds for dayly stock&price import)
        $result = $this->_doQuery("UPDATE " . $this->_getTableName('catalog_product_entity') . " cpe
                                JOIN " . $this->_getTableName('cataloginventory_stock_item') . " csi
                                    ON cpe.entity_id=csi.product_id
                                SET
                                    csi.qty=0,
                                    csi.is_in_stock=0
                                WHERE cpe.store_product_id IS NOT NULL");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('cataloginventory_stock_item') . " (
                                    product_id,
                                    stock_id,
                                    qty,
                                    is_in_stock,
                                    manage_stock,
                                    website_id
                                )(
                                  SELECT
                                    a.entity_id,
                                    1,
                                    b.stock,
                                    IF(b.stock > 0, 1, 0),
                                    1,
                                    1
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                    ON a.store_product_id=b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    qty=b.stock,
                                    is_in_stock = IF(b.stock > 0, 1, 0),
                                    manage_stock = 1
                              ");
        $result = $this->_doQuery("DELETE FROM " . $this->_getTableName('cataloginventory_stock_status'));

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('cataloginventory_stock_status') . " (
                                    product_id,
                                    website_id,
                                    stock_id,
                                    qty,
                                    stock_status
                                )(
                                  SELECT
                                    a.product_id,
                                    w.website_id,
                                    1,
                                    a.qty,
                                    IF(qty > 0, 1, 0)
                                  FROM " . $this->_getTableName('cataloginventory_stock_item') . " a
                                  INNER JOIN " . $this->_getTableName('catalog_product_entity') . " b
                                    ON a.product_id=b.entity_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON b.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    qty=a.qty,
                                    stock_status = IF(a.qty > 0, 1, 0)
                              ");

        //Add prices
        $result = $this->_doQuery("DELETE cped
                                FROM " . $this->_getTableName('catalog_product_entity_decimal') . " cped
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON cped.entity_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_decimal') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('price') . ",
                                    w.website,
                                    a.entity_id,
                                    b.price
                                  FROM " . $this->_getTableName('catalog_product_entity') . "   a
                                  INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                    ON a.store_product_id=b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.price
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_decimal') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('price') . ",
                                    0,
                                    a.entity_id,
                                    b.price
                                  FROM " . $this->_getTableName('catalog_product_entity') . "  a
                                  INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                    ON a.store_product_id=b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.price
                ");
        //Add cost
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_decimal') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('cost') . ",
                                    w.website,
                                    a.entity_id,
                                    b.cost
                                  FROM " . $this->_getTableName('catalog_product_entity') . "   a
                                  INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                    ON a.store_product_id=b.store_product_id
                                  INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                    ON a.store_product_id=w.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.cost
                              ");

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_decimal') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('cost') . ",
                                    0,
                                    a.entity_id,
                                    b.cost
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                    ON a.store_product_id=b.store_product_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.cost
                              ");

        //make products enable in FO
        $result = $this->_doQuery("DELETE cpip
                                FROM " . $this->_getTableName('catalog_product_index_price') . " cpip
                                LEFT JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                                    ON cpip.entity_id=cpe.entity_id
                                WHERE cpe.entity_id IS NULL");

        $customergroups = $this->_doQuery("
            SELECT customer_group_id
            FROM " . $this->_getTableName('customer_group')
        )->fetchAll();

        foreach ($customergroups as $key => $customerGroup) {
            $result = $this->_doQuery("
                                    INSERT INTO " . $this->_getTableName('catalog_product_index_price') . " (
                                        entity_id,
                                        customer_group_id,
                                        website_id,
                                        tax_class_id,
                                        price,
                                        final_price,
                                        min_price,
                                        max_price
                                    )(SELECT
                                        a.entity_id,
                                        " . $customerGroup['customer_group_id'] . ",
                                        w.website_id,
                                        2,
                                        b.price ,
                                        b.price ,
                                        b.price ,
                                        b.price
                                      FROM " . $this->_getTableName('catalog_product_entity') . "  a
                                      INNER JOIN " . $this->_getTableName('stock_and_prices_temp') . " b
                                        ON a.store_product_id=b.store_product_id
                                      INNER JOIN " . $this->_getTableName('products_website_temp') . " w
                                        ON a.store_product_id=w.store_product_id
                                    )
                                    ON DUPLICATE KEY UPDATE
                                        tax_class_id = 2,
                                        price = b.price,
                                        final_price = b.price,
                                        min_price = b.price,
                                        max_price = b.price
                                  ");
        }
    }

    private function _cleanCateoryProductFlatTable()
    {
        $q = 'SHOW TABLES LIKE "' . $this->_getTableName('catalog_product_flat_') . '%"';
        $quer = $this->_doQuery($q)->fetchAll();
        $result = false;
        foreach ($quer as $key => $res) {
            if (is_array($res)) {
                $catalog_product_flat = array_pop($res);
                $q = 'DELETE pf1 FROM ' . $catalog_product_flat . ' pf1
                    LEFT JOIN ' . $this->_getTableName('catalog_product_entity') . ' p
                        ON pf1.entity_id = p.entity_id
                    WHERE p.entity_id IS NULL;';
                $this->_doQuery($q);
                $this->_logImportInfo('cleaned wrong rows from ' . $catalog_product_flat);
            }
        }
        return $result;
    }

    public function runIndexer()
    {
        $this->_indexProcessor->reindexAll();
    }

    public function runStockPriceIndexer()
    {
        $this->_indexProcessor->reindexAll();
    }

    public function runCleanCache()
    {
        foreach ($this->_cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
            $cacheFrontend->clean();
        }
    }

    public function runStockPriceImport()
    {
        $safe_mode_set = ini_get('safe_mode');

        $this->initImportStatuses('PRICE STOCK');
        if ($safe_mode_set) {
            $this->_logImportInfo('safe_mode is enable. import stoped.');
            $this->_setErrorMessage('Safe_mode is enabled. Please check the documentation on how to fix this. Import stopped.');
            exit;
        }
        $store_proc = $this->checkStoreProcedureExist();

        if (!$store_proc) {
            $this->_logImportInfo('store prcedure "' . $this->_getTableName('sinch_filter_products') . '" is absent in this database. import stoped.');
            $this->_setErrorMessage('Stored procedure "' . $this->_getTableName('sinch_filter_products') . '" is absent in this database. Import stopped.');
            exit;
        }

        $file_privileg = $this->checkDbPrivileges();

        if (!$file_privileg) {
            $this->_logImportInfo("Loaddata option not set - please check the documentation on how to fix this. You dan't have privileges for LOAD DATA.");
            $this->_setErrorMessage("Loaddata option not set - please check the documentation on how to fix this. Import stopped.");
            exit;
        }
        $local_infile = $this->checkLocalInFile();
        if (!$local_infile) {
            $this->_logImportInfo("Loaddata option not set - please check the documentation on how to fix this. Add this string to  'set-variable=local-infile=0' in '/etc/my.cnf'");
            $this->_setErrorMessage("Loaddata option not set - please check the documentation on how to fix this. Import stopped.");
            exit;
        }

        if ($this->isImportNotRun() && $this->isFullImportHaveBeenRun()) {
            try {
                $q = "SELECT GET_LOCK('sinchimport', 30)";
                $quer = $this->_doQuery($q);
                $import = $this;
                $import->addImportStatus('Stock Price Start Import');

                echo("\n========IMPORTING STOCK AND PRICE========\n");

                echo "\nUpload Files...\n";

                $this->files = array(
                    FILE_STOCK_AND_PRICES,
                    FILE_PRICE_RULES
                );

                $import->uploadFiles();
                $import->addImportStatus('Stock Price Upload Files');

                echo "\nParse Stock And Prices...";

                $import->parseStockAndPrices();
                $import->addImportStatus('Stock Price Parse Products');

                echo "\nApply Customer Group Price...";
                //$import->parsePriceRules();
                //$import->addPriceRules();
                //$import->applyCustomerGroupPrice();

                $ftpCred = $this->scopeConfig->getValue(
                    'sinchimport/sinch_ftp',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                );
                $this->_eventManager->dispatch(
                    'sinch_pricerules_import_ftp',
                    [
                        'ftp_host' => $this->_dataConf["ftp_server"],
                        'ftp_username' => $this->_dataConf["username"],
                        'ftp_password' => $this->_dataConf["password"]
                    ]
                );

                $this->_logImportInfo("Start indexing  Stock & Price");
                echo "\nStart indexing  Stock & Price...";
                $import->runStockPriceIndexer();
                $import->addImportStatus('Stock Price Indexing data');
                $this->_logImportInfo("Finish indexing  Stock & Price...");
                echo "\nFinish indexing  Stock & Price...";

                $this->_logImportInfo("Start cleanin Sinch cache...");
                echo "\nStart cleanin Sinch cache...";
                $this->runCleanCache();
                $this->_logImportInfo("Finish cleanin Sinch cache...");
                echo "\nFinish cleanin Sinch cache...";

                $import->addImportStatus('Stock Price Finish import', 1);

                $this->_logImportInfo("Finish Stock & Price Sinch Import");
                echo "\n\n========>Finish Stock & Price Sinch Import......\n";

                $q = "SELECT RELEASE_LOCK('sinchimport')";
                $quer = $this->_doQuery($q);
            } catch (Exception $e) {
                $this->_setErrorMessage($e);
            }
        } else {
            if (!$this->isImportNotRun()) {
                $this->_logImportInfo("Sinchimport already run");
                echo "\nSinchimport already run...";
            } else {
                $this->_logImportInfo("Full import have never finished with success");
                echo "\nFull import have never finished with success...";
            }
        }
    }

    public function isFullImportHaveBeenRun()
    {
        $q = "SELECT COUNT(*) AS cnt
            FROM " . $this->_getTableName('sinch_import_status_statistic') . "
            WHERE import_type='FULL' AND global_status_import='Successful'";
        $res = $this->_doQuery($q)->fetch();
        if ($res['cnt'] > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function parsePriceRules()
    {
        $parseFile = $this->varDir . FILE_PRICE_RULES;
        if (filesize($parseFile) || $this->_ignore_price_rules) {
            $this->_logImportInfo("Start parse " . FILE_PRICE_RULES);

            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('price_rules_temp'));
            $this->_doQuery("CREATE TABLE " . $this->_getTableName('price_rules_temp') . "(
                            `id` int(11) NOT NULL,
                            `price_from` decimal(10,2) DEFAULT NULL,
                            `price_to` decimal(10,2) DEFAULT NULL,
                            `category_id` int(10) unsigned DEFAULT NULL,
                            `vendor_id` int(11) DEFAULT NULL,
                            `vendor_product_id` varchar(255) DEFAULT NULL,
                            `customergroup_id` varchar(32) DEFAULT NULL,
                            `marge` decimal(10,2) DEFAULT NULL,
                            `fixed` decimal(10,2) DEFAULT NULL,
                            `final_price` decimal(10,2) DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `price_from` (`price_from`,`price_to`,`vendor_id`,`category_id`,`vendor_product_id`,`customergroup_id`),
                            KEY `vendor_product_id` (`vendor_product_id`),
                            KEY `category_id` (`category_id`)
                          )
                        ");
            if (!$this->_ignore_price_rules) {

                $this->_doQuery("LOAD DATA LOCAL INFILE '" . $parseFile . "'
                              INTO TABLE " . $this->_getTableName('price_rules_temp') . "
                              FIELDS TERMINATED BY '" . $this->field_terminated_char . "'
                              OPTIONALLY ENCLOSED BY '\"'
                              LINES TERMINATED BY \"\r\n\"
                              IGNORE 1 LINES
                              (id, @vprice_from, @vprice_to, @vcategory_id, @vvendor_id, @vvendor_product_id, @vcustomergroup_id, @vmarge, @vfixed, @vfinal_price)
                              SET price_from         = nullif(@vprice_from,''),
                                  price_to           = nullif(@vprice_to,''),
                                  category_id        = nullif(@vcategory_id,''),
                                  vendor_id          = nullif(@vvendor_id,''),
                                  vendor_product_id  = nullif(@vvendor_product_id,''),
                                  customergroup_id   = nullif(@vcustomergroup_id,''),
                                  marge              = nullif(@vmarge,''),
                                  fixed              = nullif(@vfixed,''),
                                  final_price        = nullif(@vfinal_price,'')
                            ");
            }

            $this->_doQuery("ALTER TABLE " . $this->_getTableName('price_rules_temp') . "
                          ADD COLUMN `shop_category_id` int(10) unsigned DEFAULT NULL,
                          ADD COLUMN `shop_vendor_id` int(11) DEFAULT NULL,
                          ADD COLUMN `shop_vendor_product_id` varchar(255) DEFAULT NULL,
                          ADD COLUMN `shop_customergroup_id` varchar(32) DEFAULT NULL
                        ");

            $this->_doQuery("UPDATE " . $this->_getTableName('price_rules_temp') . " prt
                          JOIN " . $this->_getTableName('catalog_category_entity') . " cce
                            ON prt.category_id = cce.store_category_id
                          SET prt.shop_category_id = cce.entity_id");

            $this->_doQuery("UPDATE " . $this->_getTableName('price_rules_temp') . " prt
                          JOIN " . $this->_getTableName('sinch_manufacturers') . " sicm
                            ON prt.vendor_id = sicm.sinch_manufacturer_id
                          SET prt.shop_vendor_id = sicm.shop_option_id");

            $this->_doQuery("UPDATE " . $this->_getTableName('price_rules_temp') . " prt
                          JOIN " . $this->_getTableName('sinch_products_mapping') . " sicpm
                            ON prt.vendor_product_id = sicpm.product_sku
                          SET prt.shop_vendor_product_id = sicpm.sku");

            $this->_doQuery("UPDATE " . $this->_getTableName('price_rules_temp') . " prt
                          JOIN " . $this->_getTableName('customer_group') . " cg
                            ON prt.customergroup_id = cg.customer_group_id
                          SET prt.shop_customergroup_id = cg.customer_group_id");

            $this->_doQuery("DELETE FROM " . $this->_getTableName('price_rules_temp') . "
                          WHERE
                            (category_id IS NOT NULL AND shop_category_id IS NULL) OR
                            (vendor_id IS NOT NULL AND shop_vendor_id IS NULL) OR
                            (vendor_product_id IS NOT NULL AND shop_vendor_product_id IS NULL) OR
                            (customergroup_id IS NOT NULL AND shop_customergroup_id IS NULL)
                        ");
            $this->_doQuery("DROP TABLE IF EXISTS " . $this->_getTableName('sinch_price_rules'));
            $this->_doQuery("RENAME TABLE " . $this->_getTableName('price_rules_temp') . "
                          TO " . $this->_getTableName('sinch_price_rules'));

            $this->_logImportInfo("Finish parse " . FILE_PRICE_RULES);
        } else {
            $this->_logImportInfo("Wrong file " . $parseFile);
        }
        $this->_logImportInfo(" ");
    }

    public function addPriceRules()
    {
        if (!$this->check_table_exist('import_pricerules_standards')) {
            return;
        }

        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('import_pricerules') . " (
                                    id,
                                    price_from,
                                    price_to,
                                    vendor_id,
                                    category_id,
                                    vendor_product_id,
                                    customergroup_id,
                                    marge,
                                    fixed,
                                    final_price
                                )(SELECT
                                    id,
                                    price_from,
                                    price_to,
                                    shop_vendor_id,
                                    shop_category_id,
                                    shop_vendor_product_id,
                                    shop_customergroup_id,
                                    marge,
                                    fixed,
                                    final_price
                                  FROM " . $this->_getTableName('sinch_price_rules') . " a
                               )
                                ON DUPLICATE KEY UPDATE
                                    id                  = a.id,
                                    price_from          = a.price_from,
                                    price_to            = a.price_to,
                                    vendor_id           = a.shop_vendor_id,
                                    category_id         = a.shop_category_id,
                                    vendor_product_id   = a.shop_vendor_product_id,
                                    customergroup_id    = a.shop_customergroup_id,
                                    marge               = a.marge,
                                    fixed               = a.fixed,
                                    final_price         = a.final_price
                              ");

    }

    private function check_table_exist($table)
    {

        $q = "SHOW TABLES LIKE \"%" . $this->_getTableName($table) . "%\"";

        $res = $this->_doQuery($q)->fetchAll();

        $i = 0;

        foreach ($res as $key => $value) {
            $i++;
        }

        return ($i);
    }

    public function applyCustomerGroupPrice()
    {
        if (!$this->check_table_exist('import_pricerules_standards')) {
            return;
        }
        $this->_getProductsForCustomerGroupPrice();
        $pricerulesArray = $this->_getPricerulesList();
        if (is_array($pricerulesArray)) {
            $this->_doQuery("TRUNCATE TABLE " . $this->_getTableName('catalog_product_entity_group_price'));
            $this->_doQuery("TRUNCATE TABLE " . $this->_getTableName('catalog_product_index_group_price'));

        }

        foreach ($pricerulesArray as $pricerule) {
            $this->_logImportInfo("Calculation group price for rule " . $pricerule['id'] . "
                        (\nname          =   " . $pricerule['name'] . "
                         \nfinal_price   =   " . $pricerule['final_price'] . "
                         \nprice_from    =   " . $pricerule['price_from'] . "
                         \nprice_to      =   " . $pricerule['price_to'] . "
                         \nvendor_id     =   " . $pricerule['vendor_id'] . "
                         \ncategory_id   =   " . $pricerule['category_id'] . "
                         \nproduct_entity_id =   " . $pricerule['product_entity_id'] . "
                         \nvendor_product_id =   " . $pricerule['vendor_product_id'] . "
                         \ncustomergroup_id  =   " . $pricerule['customergroup_id'] . "
                         \ndistributor_id    =   " . $pricerule['distributor_id'] . "
                         \nrating            =   " . $pricerule['rating'] . "
                         \nmarge             =   " . $pricerule['marge'] . "
                         \nfixed             =   " . $pricerule['fixed'] . "
                         \nallow_subcat      =   " . $pricerule['allow_subcat'] . "
                         \nstore_id          =   " . $pricerule['store_id'] . "
                        )");

            $vendor_product_id_str = "'" . str_replace(';', "','", $pricerule['vendor_product_id']) . "'";
            $where = "";
            if (empty($pricerule['marge'])) $marge = "NULL";
            else $marge = $pricerule['marge'];

            if (empty($pricerule['fixed'])) $fixed = "NULL";
            else $fixed = $pricerule['fixed'];

            if (empty($pricerule['final_price'])) $final_price = "NULL";
            else $final_price = $pricerule['final_price'];

            if (!empty($pricerule['price_from'])) $where .= " AND a.price > " . $pricerule['price_from'];

            if (!empty($pricerule['price_to'])) $where .= " AND a.price < " . $pricerule['price_to'];

            if (!empty($pricerule['vendor_id'])) $where .= " AND a.manufacturer_id = " . $pricerule['vendor_id'];

            if (!empty($pricerule['product_entity_id'])) $where .= " AND a.product_id = '" . $pricerule['product_entity_id'] . "'";

            if (!empty($pricerule['vendor_product_id'])) $where .= " AND a.sku IN (" . $vendor_product_id_str . ")";

            if (!empty($pricerule['allow_subcat'])) {
                if (!empty($pricerule['category_id'])) {
                    $children_cat = $this->get_all_children_cat($pricerule['category_id']);
                    $where .= " AND a.category_id IN  (" . $children_cat . ")";
                }
            } else {
                if (!empty($pricerule['category_id'])) $where .= " AND a.category_id = " . $pricerule['category_id'];
            }

            $customer_group_id_array = [];
            if (strstr($pricerule['customergroup_id'], ",")) {
                $customer_group_id_array = explode(",", $pricerule['customergroup_id']);
            } else {
                $customer_group_id_array[0] = $pricerule['customergroup_id'];
            }

            foreach ($customer_group_id_array as $customer_group_id) {
                if (isset($customer_group_id) && $customer_group_id >= 0) {
                    $query = "
                    INSERT INTO " . $this->_getTableName('catalog_product_entity_group_price') . "                             (entity_id,
                     all_groups,
                     customer_group_id,
                     value,
                     website_id
                    )
                    (SELECT
                      a.product_id,
                      0,
                      " . $customer_group_id . ",
                      " . $this->_getTableName('func_calc_price') . "(
                                        a.price,
                                      " . $marge . " ,
                                      " . $fixed . ",
                                      " . $final_price . "),
                      0
                     FROM " . $this->_getTableName('sinch_products_for_customer_group_price_temp') . " a
                     WHERE true " . $where . "
                    )
                    ON DUPLICATE KEY UPDATE
                    value =
                        " . $this->_getTableName('func_calc_price') . "(
                                        a.price,
                                        " . $marge . " ,
                                        " . $fixed . ",
                                        " . $final_price . ")
                    ";

                    $this->_doQuery($query);
                    if (!empty($pricerule['store_id']) && $pricerule['store_id'] > 0) {
                        $query = "
                      INSERT INTO " . $this->_getTableName('catalog_product_index_group_price') . "                             (entity_id,
                              customer_group_id,
                              price,
                              website_id
                              )
                      (SELECT
                       a.product_id,
                       " . $customer_group_id . ",
                       " . $this->_getTableName('func_calc_price') . "(
                                  a.price,
                                  " . $marge . " ,
                                  " . $fixed . ",
                                  " . $final_price . "),
                      " . $pricerule['store_id'] . "
                       FROM " . $this->_getTableName('sinch_products_for_customer_group_price_temp') . " a
                       WHERE true " . $where . "
                      )
                      ON DUPLICATE KEY UPDATE
                      price =
                      " . $this->_getTableName('func_calc_price') . "(
                              a.price,
                              " . $marge . " ,
                              " . $fixed . ",
                              " . $final_price . ")
                      ";

                        $this->_doQuery($query);

                    }
                }
            }
        }
    }

    public function _getProductsForCustomerGroupPrice()
    {
        // TEMPORARY
        $this->_doQuery(" DROP TABLE IF EXISTS " . $this->_getTableName('sinch_products_for_customer_group_price_temp'));
        $this->_doQuery("
                CREATE TABLE " . $this->_getTableName('sinch_products_for_customer_group_price_temp') . "
                (
                 `category_id`       int(10) unsigned NOT NULL default '0',
                 `product_id`        int(10) unsigned NOT NULL default '0',
                 `store_product_id`  int(10) NOT NULL default '0',
                 `sku` varchar(64) DEFAULT NULL COMMENT 'SKU',
                 `manufacturer_id`  int(10) NOT NULL default '0',
                 `price` decimal(15,4) DEFAULT NULL,
                 UNIQUE KEY `UNQ_CATEGORY_PRODUCT` (`product_id`,`category_id`),
                 KEY `CATALOG_CATEGORY_PRODUCT_CATEGORY` (`category_id`),
                 KEY `CATALOG_CATEGORY_PRODUCT_PRODUCT` (`product_id`)
                )");

        $result = $this->_doQuery("
                INSERT INTO " . $this->_getTableName('sinch_products_for_customer_group_price_temp') . "
                (category_id, product_id, store_product_id, sku)
                (SELECT
                 ccp.category_id,
                 ccp.product_id,
                 cpe.store_product_id,
                 cpe.sku
                 FROM " . $this->_getTableName('catalog_category_product') . " ccp
                 JOIN " . $this->_getTableName('catalog_product_entity') . " cpe
                 ON ccp.product_id = cpe.entity_id
                 WHERE cpe.store_product_id IS NOT NULL)");

        $result = $this->_doQuery("
                 UPDATE  " . $this->_getTableName('sinch_products_for_customer_group_price_temp') . " pfcgpt
                 JOIN " . $this->_getTableName('catalog_product_entity_int') . " cpei
                 ON pfcgpt.product_id = cpei.entity_id
                 AND cpei.attribute_id = " . $this->_getProductAttributeId('manufacturer') . "
                 SET pfcgpt.manufacturer_id = cpei.value
        ");

        $result = $this->_doQuery("
                 UPDATE  " . $this->_getTableName('sinch_products_for_customer_group_price_temp') . " pfcgpt
                 JOIN " . $this->_getTableName('catalog_product_entity_decimal') . " cped
                 ON pfcgpt.product_id = cped.entity_id
                 AND cped.attribute_id = " . $this->_getProductAttributeId('price') . "
                 SET pfcgpt.price = cped.value
        ");
    }

    protected function _getPricerulesList()
    {
        $rulesArray = [];

        $result = $this->_doQuery("
            SELECT *
            FROM " . $this->_getTableName('import_pricerules') . "
            ORDER BY rating DESC
        ")->fetchAll();

        foreach ($result as $key => $res) {
            $rulesArray[$res['id']] = $res;
        }

        return $rulesArray;
    }

    private function get_all_children_cat($entity_id)
    {
        $children_cat = "'" . $entity_id . "'" . $this->get_all_children_cat_recursive($entity_id);
        return ($children_cat);
    }

    private function get_all_children_cat_recursive($entity_id)
    {
        $q = "SELECT entity_id
           FROM " . $this->_getTableName('catalog_category_entity') . "
           WHERE parent_id=" . $entity_id;
        $result = $this->_doQuery($q)->fetchAll();
        $children_cat = '';
        foreach ($result as $key => $res) {
            $children_cat .= ", '" . $res['entity_id'] . "'";
            $children_cat .= $this->get_all_children_cat_recursive($res['entity_id']);
        }
        return ($children_cat);
    }

    public function getProductDescription($entity_id)
    {
        $this->loadProductParams($entity_id);
        $this->loadProductStarfeatures($entity_id);
        $this->loadGalleryPhotos($entity_id);
        \Magento\Framework\Profiler::start('Bintime FILE RELATED');
        $this->loadRelatedProducts($entity_id);
        \Magento\Framework\Profiler::stop('Bintime FILE RELATED');

        return true;
    }

    private function loadProductParams($entity_id)
    {
        $store_product_id = $this->getStoreProductIdByEntity($entity_id);
        if (!$store_product_id) {
            return;
        }
        $q = "SELECT
                sinch_product_id,
                product_sku,
                product_name,
                sinch_manufacturer_id,
                store_category_id,
                main_image_url,
                thumb_image_url,
                medium_image_url,
                specifications,
                description,
                specifications
            FROM " . $this->_getTableName('sinch_products') . "
            WHERE store_product_id =" . $store_product_id;
        $product = $this->_doQuery($q)->fetch();

        $this->productDescription = (string)substr($product['description'], 50, 0);
        $this->fullProductDescription = (string)$product['description'];
        $this->lowPicUrl = (string)$product["medium_image_url"];//thumb_image_url"];
        $this->highPicUrl = (string)$product["main_image_url"];
        $this->productName = (string)$product["product_name"];
        $this->productId = (string)$product['product_sku'];
        $this->specifications = (string)$product['specifications'];
        $this->sinchProductId = (string)$product['sinch_product_id'];
        if ($product['sinch_manufacturer_id']) {
            $q = "SELECT manufacturer_name
                FROM " . $this->_getTableName('sinch_manufacturers') . "
                WHERE sinch_manufacturer_id=" . $product['sinch_manufacturer_id'];
            $manufacturer = $this->_doQuery($q)->fetch();
            $this->vendor = (string)$manufacturer['manufacturer_name'];
        }

        $q = "SELECT DISTINCT ean_code
            FROM " . $this->_getTableName('sinch_ean_codes') . " sec
            JOIN " . $this->_getTableName('sinch_products') . " sp
                ON sec.product_id=sp.sinch_product_id
            WHERE sp.store_product_id=" . $store_product_id;
        $eANRes = $this->_doQuery($q)->fetchAll();

        foreach ($eANRes as $key => $value) {
            $EANarr[] = $value['ean_code'];
        }

        $EANstr = '';
        $EANstr = implode(", ", $EANarr);
        $this->EAN = (string)$EANstr;
    }

    private function getStoreProductIdByEntity($entity_id)
    {
        $res = $this->_doQuery("SELECT store_product_id
                         FROM " . $this->_getTableName('sinch_products_mapping') . "
                         WHERE entity_id=" . $entity_id)->fetch();
        return ($res['store_product_id']);
    }

    private function loadProductStarfeatures($entity_id)
    {
        $descriptionArray = [];
        $product_info_features = $this->_doQuery("
                SELECT c.feature_name AS name, b.text AS value
                FROM " . $this->_getTableName('sinch_product_features') . " a
                INNER JOIN " . $this->_getTableName('sinch_restricted_values') . " b
                    ON a.restricted_value_id = b.restricted_value_id
                INNER JOIN " . $this->_getTableName('sinch_categories_features') . " c
                    ON b.category_feature_id = c.category_feature_id
                WHERE a.sinch_product_id = '" . $this->sinchProductId . "'"
        )->fetchAll();

        foreach ($product_info_features as $key => $features) {
            $descriptionArray[$features['name']] = $features['value'];
        }

        $this->productDescriptionList = $descriptionArray;
    }

    /**
     * load Gallery array from XML
     */
    public function loadGalleryPhotos($entity_id)
    {
        $store_product_id = $this->getStoreProductIdByEntity($entity_id);
        if (!$store_product_id) {
            return;
        }
        $res = $this->_doQuery("SELECT COUNT(*) AS cnt
                         FROM " . $this->_getTableName('sinch_products_pictures_gallery') . "
                         WHERE store_product_id=" . $store_product_id)->fetch();

        if (!$res || !$res['cnt']) {
            return $this;
        }
        $q = "SELECT
                image_url as Pic,
                thumb_image_url as ThumbPic
            FROM " . $this->_getTableName('sinch_products_pictures_gallery') . "
            WHERE store_product_id=" . $store_product_id;

        $photos = $this->_doQuery($q)->fetchAll();

        foreach ($photos as $key => $photo) {
            $picHeight = (int)500;
            $picWidth = (int)500;
            $thumbUrl = (string)$photo["ThumbPic"];
            $picUrl = (string)$photo["Pic"];

            array_push($this->galleryPhotos, array(
                'height' => $picHeight,
                'width' => $picWidth,
                'thumb' => $thumbUrl,
                'pic' => $picUrl
            ));
        }

        return $this;
    }

    private function loadRelatedProducts($entity_id)
    {
        $this->sinchProductId;
        if (!$this->sinchProductId) {
            return;
        }
        $q = "SELECT
                st_prod.sinch_product_id,
                st_prod.product_sku,
                st_prod.product_name,
                st_prod.sinch_manufacturer_id,
                st_prod.store_category_id,
                st_prod.main_image_url,
                st_prod.thumb_image_url,
                st_prod.medium_image_url,
                st_prod.specifications,
                st_prod.description,
                st_prod.specifications,
                st_manuf.manufacturer_name,
                st_manuf.manufacturers_image
            FROM " . $this->_getTableName('sinch_related_products') . " st_rel_prod
            JOIN " . $this->_getTableName('sinch_products') . " st_prod
                ON st_rel_prod.related_sinch_product_id=st_prod.sinch_product_id
            JOIN " . $this->_getTableName('sinch_manufacturers') . " st_manuf
                ON st_prod.sinch_manufacturer_id=st_manuf.sinch_manufacturer_id
            WHERE st_rel_prod.sinch_product_id=" . $this->sinchProductId;

        $relatedProducts = $this->_doQuery($q)->fetchAll();
        foreach ($relatedProducts as $key => $relatedProduct) {
            $productArray = [];
            $productArray['name'] = (string)$relatedProduct['product_name'];
            $productArray['thumb'] = (string)$relatedProduct['thumb_image_url'];
            $mpn = (string)$relatedProduct['product_sku'];
            $productSupplierId = (int)$relatedProduct['sinch_manufacturer_id'];
            $productArray['supplier_thumb'] = (string)($relatedProduct['manufacturers_image']);
            $productArray['supplier_name'] = (string)$relatedProduct['manufacturer_name'];

            $this->relatedProducts[$mpn] = $productArray;
        }
    }

    public function getProductName()
    {
        return $this->productName;
    }

    public function getProductDescriptionList()
    {
        return $this->productDescriptionList;
    }

    public function getProductSpecifications()
    {
        return $this->specifications;
    }

    public function getShortProductDescription()
    {
        return $this->productDescription;
    }

    public function getFullProductDescription()
    {
        return $this->fullProductDescription;
    }

    public function getLowPicUrl()
    {
        return $this->highPicUrl;
    }

    public function getRelatedProducts()
    {
        return $this->relatedProducts;
    }

    public function getVendor()
    {
        return $this->vendor;
    }

    public function getMPN()
    {
        return $this->productId;
    }

    public function getEAN()
    {
        return $this->EAN;
    }

    public function getGalleryPhotos()
    {
        return $this->galleryPhotos;
    }

    public function reloadProductImage($entity_id)
    {
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('sinch_products') . " b
                                    ON a.store_product_id = b.store_product_id
                                  WHERE a.entity_id=$entity_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.main_image_url
                              ");
        // image for specific web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
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
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('sinch_products') . " b
                                    ON a.store_product_id = b.store_product_id
                                  WHERE a.entity_id=$entity_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.main_image_url
                              ");
        // small_image for specific web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('small_image') . ",
                                    w.store_id,
                                    a.entity_id,
                                    b.main_image_url
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('sinch_products') . " b
                                    ON a.store_product_id = b.store_product_id
                                  WHERE a.entity_id=$entity_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.main_image_url
                              ");
        // small_image for all web sites
        $result = $this->_doQuery("
                                INSERT INTO " . $this->_getTableName('catalog_product_entity_varchar') . " (
                                    attribute_id,
                                    store_id,
                                    entity_id,
                                    value
                                )(
                                  SELECT
                                    " . $this->_getProductAttributeId('small_image') . ",
                                    0,
                                    a.entity_id,
                                    b.main_image_url
                                  FROM " . $this->_getTableName('catalog_product_entity') . " a
                                  INNER JOIN " . $this->_getTableName('store') . " w
                                  INNER JOIN " . $this->_getTableName('sinch_products') . " b
                                    ON a.store_product_id = b.store_product_id
                                  WHERE a.entity_id=$entity_id
                                )
                                ON DUPLICATE KEY UPDATE
                                    value = b.main_image_url
                ");
    }

    public function valid_utf($string, $new_line = true)
    {
        $string = preg_replace('/™/', '&#8482;', $string);
        $string = preg_replace("/®/", '&reg;', $string);
        $string = preg_replace("/≈/", '&asymp;', $string);
        $string = preg_replace("/" . chr(226) . chr(128) . chr(157) . "/", '&quot;', $string);
        $string = preg_replace("/" . chr(226) . chr(128) . chr(153) . "/", '&prime;', $string);
        $string = preg_replace("/°/", '&deg;', $string);
        $string = preg_replace("/±/", '&plusmn;', $string);
        $string = preg_replace("/µ/", '&micro;', $string);
        $string = preg_replace("/²/", '&sup2;', $string);
        $string = preg_replace("/³/", '&sup3;', $string);
        $string = preg_replace('/\xe2\x80\x93/', '-', $string);
        $string = preg_replace('/\xe2\x80\x99/', '\'', $string);
        $string = preg_replace('/\xe2\x80\x9c/', ' ', $string);
        $string = preg_replace('/\xe2\x80\x9d/', ' ', $string);

        return utf8_decode($string);
    }

    public function set_imports_failed()
    {
        $this->_doQuery("UPDATE " . $this->import_status_statistic_table . "
                      SET global_status_import='Failed'
                      WHERE global_status_import='Run'");
    }

    public function getImportStatusHistory()
    {
        $res = $this->_doQuery("SELECT COUNT(*) as cnt FROM " . $this->import_status_statistic_table)->fetch();
        $cnt = $res['cnt'];
        $StatusHistory_arr = [];
        if ($cnt > 0) {
            $a = (($cnt > 7) ? ($cnt - 7) : 0);
            $b = $cnt;
            $q = "SELECT
                    id,
                    start_import,
                    finish_import,
                    import_type,
                    number_of_products,
                    global_status_import,
                    detail_status_import
                FROM " . $this->import_status_statistic_table . "
                ORDER BY start_import limit " . $a . ", " . $b;
            $result = $this->_doQuery($q)->fetchAll();
            foreach ($result as $key => $res) {
                $StatusHistory_arr[] = $res;
            }
        }
        return $StatusHistory_arr;
    }

    public function getDateOfLatestSuccessImport()
    {
        $q = "SELECT start_import, finish_import
            FROM " . $this->import_status_statistic_table . "
            WHERE global_status_import='Successful'
            ORDER BY id DESC LIMIT 1";
        $imp_date = $this->_doQuery($q)->fetch();
        return $imp_date['start_import'];
    }

    public function getDataOfLatestImport()
    {
        $q = "SELECT
                start_import,
                finish_import,
                import_type,
                number_of_products,
                global_status_import,
                detail_status_import,
                number_of_products,
                error_report_message
            FROM " . $this->import_status_statistic_table . "
            ORDER BY id DESC LIMIT 1";

        $imp_status = $this->_doQuery($q)->fetch();

        return $imp_status;
    }

    public function getImportStatuses()
    {
        $q = "SELECT id, message, finished
            FROM " . $this->import_status_table . "
            ORDER BY id LIMIT 1";
        $res = $this->_doQuery($q)->fetch();
        $messages = [];
        if ($res) {
            $messages = array('message' => $res['message'], 'id' => $res['id'], 'finished' => $res['finished']);
            $id = $res['id'];
        }
        if (!empty($id)) {
            $q = "DELETE FROM " . $this->import_status_table . " WHERE id=" . $id;
            $this->_doQuery($q);
        }
        return $messages;
    }

    public function checkMemory()
    {
        $check_code = 'memory';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $memInfoContent = file_get_contents("/proc/meminfo");
        if ($memInfoContent === false) {
            return array(
                'error',
                $Caption,
                $CheckValue,
                0,
                $CheckMeasure,
                'Cannot read /proc/meminfo for RAM information',
                'Make sure open_basedir permits access to /proc/meminfo (or is off) and that this is a *nix system'
            );
        }
        $data = explode("\n", $memInfoContent);

        foreach ($data as $line) {
            $lineParts = explode(":", $line);
            if (count($lineParts) < 2) continue;
            list($key, $val) = $lineParts;

            if ($key == 'MemTotal') {
                $val = trim($val);
                $value = (int)substr($val, 0, strpos($val, ' kB'));
                $measure = substr($val, strpos($val, ' kB'));

                $retvalue['memory']['value'] = (integer)(((float)$value) / 1024);
                $retvalue['memory']['measure'] = 'MB';
            }
        }

        $errmsg = '';
        $fixmsg = '';
        if ($retvalue['memory']['value'] <= $CheckValue) {
            $errmsg = sprintf($ErrorMessage, $retvalue['memory']['value']);
            $fixmsg = sprintf($FixMessage, " " . $CheckValue . " " . $CheckMeasure);
            $retvalue['memory']['status'] = 'error';
        } else {
            $retvalue['memory']['status'] = 'OK';
        }

        return array(
            $retvalue['memory']['status'],
            $Caption,
            $CheckValue,
            $retvalue['memory']['value'],
            $CheckMeasure,
            $errmsg,
            $fixmsg
        );
    }

    public function checkLoaddata()
    {
        $check_code = 'loaddata';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $conn = $this->_resourceConnection->getConnection('core_read');
        $result = $conn->query("SHOW VARIABLES LIKE 'local_infile'");
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $value = $row['Value'];
        $errmsg = '';
        $fixmsg = '';
        if ($value != $CheckValue) {
            $errmsg .= $ErrorMessage . " " . $value . " " . $CheckMeasure;
            $fixmsg .= $FixMessage;
            $status = 'error';
        } else {
            $errmsg .= 'none';
            $fixmsg .= 'none';
            $status = 'OK';
        }

        $ret = [];
        array_push($ret, $status, $Caption, $CheckValue, $value, $CheckMeasure, $errmsg, $fixmsg);

        return $ret;
    }

    public function checkPhpsafemode()
    {
        $check_code = 'phpsafemode';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $a = ini_get('safe_mode');
        if ($a) {
            $value = 'ON';
        } else {
            $value = 'OFF';
        }

        $errmsg = '';
        $fixmsg = '';
        if ($value != $CheckValue) {
            $errmsg .= sprintf($ErrorMessage, " " . $value . " " . $CheckMeasure);
            $fixmsg .= sprintf($FixMessage, " " . $CheckValue . " " . $CheckMeasure);
            $status = 'error';
        } else {
            $errmsg .= 'none';
            $fixmsg .= 'none';
            $status = 'OK';
        }

        $ret = [];
        array_push($ret, $status, $Caption, $CheckValue, $value, $CheckMeasure, $errmsg, $fixmsg);

        return $ret;
    }

    public function checkWaittimeout()
    {
        $check_code = 'waittimeout';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $conn = $this->_resourceConnection->getConnection('core_read');
        $result = $conn->query("SHOW VARIABLES LIKE 'wait_timeout'");
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $value = $row['Value'];

        $errmsg = '';
        $fixmsg = '';
        if ($value <= $CheckValue) {
            $errmsg .= $ErrorMessage . " " . $value . " " . $CheckMeasure;
            $fixmsg .= sprintf($FixMessage, " " . $CheckValue);
            $status = 'error';
        } else {
            $errmsg .= 'none';
            $fixmsg .= 'none';
            $status = 'OK';
        }

        $ret = [];
        array_push($ret, $status, $Caption, $CheckValue, $value, $CheckMeasure, $errmsg, $fixmsg);

        return $ret;
    }

    public function checkInnodbbufferpoolsize()
    {
        $check_code = 'innodbbufpool';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $conn = $this->_resourceConnection->getConnection('core_read');
        $result = $conn->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'");
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $value = (int)($row['Value'] / (1024 * 1024));

        $errmsg = '';
        $fixmsg = '';
        if ($value < $CheckValue) {
            $errmsg .= sprintf($ErrorMessage, " " . $value . " " . $CheckMeasure);
            $fixmsg .= sprintf($FixMessage, " " . $CheckValue . " " . $CheckMeasure);
            $status = 'error';
        } else {
            $errmsg .= 'none';
            $fixmsg .= 'none';
            $status = 'OK';
        }

        $ret = [];
        array_push($ret, $status, $Caption, $CheckValue, $value, $CheckMeasure, $errmsg, $fixmsg);

        return $ret;
    }

    public function checkPhprunstring()
    {
        $check_code = 'php5run';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $value = trim(PHP_RUN_STRING);
        $errmsg = '';
        $fixmsg = '';
        $status = 'OK';

        if (!defined('PHP_RUN_STRING')) {
            $errmsg .= "You haven't installed PHP CLI";
            $fixmsg .= "Install PHP CLI.";
            $status = 'error';
        }

        return array(
            $status,
            $Caption,
            $CheckValue,
            $value,
            $CheckMeasure,
            $errmsg,
            $fixmsg
        );
    }

    public function checkChmodwgetdatafile()
    {
        $check_code = 'chmodwget';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $datafile_csv = '/usr/bin/wget';

        $value = substr(sprintf('%o', fileperms($datafile_csv)), -4);

        $CheckValue_own = $CheckValue{1};
        $CheckValue_group = $CheckValue{2};
        $CheckValue_other = $CheckValue{3};

        $value_own = $value{1};
        $value_group = $value{2};
        $value_other = $value{3};

        $errmsg = '';
        $fixmsg = '';

        if (($value_own < $CheckValue_own) || ($value_group < $CheckValue_group) || ($value_other < $CheckValue_other)) {
            $errmsg .= $ErrorMessage;
            $fixmsg .= $FixMessage;
            $status = 'error';
        } else {
            $errmsg .= 'none';
            $fixmsg .= 'none';
            $status = 'OK';
        }

        $ret = [];
        array_push($ret, $status, $Caption, $CheckValue, $value, $CheckMeasure, $errmsg, $fixmsg);

        return $ret;
    }

    public function checkProcedure()
    {
        $check_code = 'routine';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $conn = $this->_resourceConnection->getConnection('core_read');
        $storedFunctionName = $this->_getTableName('sinch_filter_products');
        $result = $conn->query("SHOW PROCEDURE STATUS LIKE '$storedFunctionName'");
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $value = $row['Name'];

        $errmsg = '';
        $fixmsg = '';
        if ($value != $CheckValue) {
            $errmsg .= $ErrorMessage;
            $fixmsg .= $FixMessage;
            $status = 'error';
        } else {
            $errmsg .= 'none';
            $fixmsg .= 'none';
            $status = 'OK';
        }

        $ret = [];
        array_push($ret, $status, $Caption, $CheckValue, $value, $CheckMeasure, $errmsg, $fixmsg);

        return $ret;
    }

    public function checkConflictsWithInstalledModules()
    {
        $check_code = 'conflictwithinstalledmodules';

        $row = $this->_doQuery("SELECT * FROM $tableName WHERE check_code = '$check_code'")->fetch();

        $Caption = $row['caption'];
        $CheckValue = $row['check_value'];
        $CheckMeasure = $row['check_measure'];
        $ErrorMessage = $row['error_msg'];
        $FixMessage = $row['fix_msg'];

        $retvalue = [];
        $retvalue["'$check_code'"] = [];

        $config_file = (Mage::app()->getConfig()->getNode()->asXML());

        $errmsg = $ErrorMessage;
        $fixmsg = $FixMessage;

        $status = 'OK';

        if (!strstr($config_file, '<image>Bintime_Sinchimport_Helper_Image</image>')) {
            $errmsg .= " Can't find <image>Bintime_Sinchimport_Helper_Image</image> in  <helpers><catalog></catalog></helpers>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<product_image>Bintime_Sinchimport_Model_Image</product_image>')) {
            $errmsg .= " Can't find <product_image>Bintime_Sinchimport_Model_Image</product_image> in  <models><catalog></catalog></models>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<category>Bintime_Sinchimport_Model_Category</category>')) {
            $errmsg .= " Can't find <category>Bintime_Sinchimport_Model_Category</category> in  <models><catalog></catalog></models>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<product_compare_list>Bintime_Sinchimport_Block_List</product_compare_list>')) {
            $errmsg .= " Can't find <product_compare_list>Bintime_Sinchimport_Block_List</product_compare_list> in  <blocks><catalog></catalog></blocks>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<product_view_media>Bintime_Sinchimport_Block_Product_View_Media</product_view_media>')) {
            $errmsg .= " Can't find <product_view_media>Bintime_Sinchimport_Block_Product_View_Media</product_view_media> in  <blocks><catalog></catalog></blocks>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<product>Bintime_Sinchimport_Model_Product</product>')) {
            $errmsg .= " Can't find <product>Bintime_Sinchimport_Model_Product</product> in  <models><catalog></catalog></models>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<layer_filter_price>Bintime_Sinchimport_Model_Layer_Filter_Price</layer_filter_price>')) {
            $errmsg .= " Can't find <layer_filter_price>Bintime_Sinchimport_Model_Layer_Filter_Price</layer_filter_price> in  <models><catalog></catalog><models>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<layer_view>Bintime_Sinchimport_Block_Layer_View</layer_view>')) {
            $errmsg .= " Can't find <layer_view>Bintime_Sinchimport_Block_Layer_View</layer_view> in  <blocks><catalog></catalog></blocks>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<layer>Bintime_Sinchimport_Model_Layer</layer>')) {
            $errmsg .= " Can't find <layer>Bintime_Sinchimport_Model_Layer</layer> in  <models><catalog></catalog><models>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }

        if (!strstr($config_file, '<layer_filter_price>Bintime_Sinchimport_Model_Resource_Layer_Filter_Price</layer_filter_price>')) {
            $errmsg .= " Can't find <layer_filter_price>Bintime_Sinchimport_Model_Resource_Layer_Filter_Price</layer_filter_price> in  <models><catalog_resource_eav_mysql4></catalog_resource_eav_mysql4></models>";
            $fixmsg = $FixMessage;
            $status = 'error';
        }
        if ($status == 'OK') {
            $errmsg = 'none';
            $fixmsg = 'none';
        }
        return array(
            $status,
            $Caption,
            $CheckValue,
            '',
            $CheckMeasure,
            $errmsg,
            $fixmsg
        );
    }

    public function getSinchDistribotorsTableHtml($entity_id = null)
    {
        if (!$entity_id) {
            $entity_id = Mage::registry('current_product')->getId();
        }
        if (!$entity_id) {
            return '';
        }

        $distributors_stock_price = $this->getDistributorStockPriceByProductid($entity_id);
        $distributors_table = '
            <table>
                <thead>
                   <tr class="headings">
                     <th>Supplier</th>
                     <th>Stock</th>
                     <th>price</th>
                   </tr>
                </thead>
                <tbody>';
        $i = 1;
        foreach ($distributors_stock_price as $offer) {
            if ($i > 0) {
                $class = "even pointer";
                $i = 0;
            } else {
                $class = "pointer";
                $i = 1;
            }
            $distributors_table .= '
                  <tr class="' . $class . '">
                        <td nowrap  style="font-weight: normal">' . $offer['distributor_name'] . '</td>
                        <td style="font-weight: normal">' . $offer['stock'] . '</td>
                        <td style="font-weight: normal">' . Mage::helper('core')->currency($offer['cost']) . '</td>
                   </tr>';
        }
        $distributors_table .= '
                </tbody>
            </table>
         ';
        return $distributors_table;
    }

    private function getDistributorStockPriceByProductid($entity_id)
    {
        $store_product_id = $this->getStoreProductIdByEntity($entity_id);
        if (!$store_product_id) {
            //      echo "AAAAAAA"; exit;
            return;
        }
        $q = "SELECT
                d.distributor_name,
                d.website,
                dsp.stock,
                dsp.cost,
                dsp.distributor_sku,
                dsp.distributor_category,
                dsp.eta
            FROM " . $this->_getTableName('sinch_distributors_stock_and_price') . " dsp
            JOIN " . $this->_getTableName('sinch_distributors') . " d
            ON dsp.distributor_id = d.distributor_id
            WHERE store_product_id =" . $store_product_id;
        $result = $this->_doQuery($q)->fetchAll();
        $offers = null;

        foreach ($result as $key => $res) {
            $offers[] = $row;
        }

        return $offers;
    }

    private function _reindexProductUrlKey()
    {
        $this->_doQuery("SET FOREIGN_KEY_CHECKS=0");
        $this->_doQuery("TRUNCATE TABLE " . $this->_getTableName('url_rewrite'));
        $this->_doQuery("SET FOREIGN_KEY_CHECKS=1");
        $this->_doQuery("SET FOREIGN_KEY_CHECKS=0");
        $this->_doQuery("TRUNCATE TABLE " . $this->_getTableName('catalog_url_rewrite_product_category'));
        $this->_doQuery("SET FOREIGN_KEY_CHECKS=1");
        $this->_doQuery("
            UPDATE " . $this->_getTableName('catalog_product_entity_varchar') . "
                SET value = ''
            WHERE attribute_id=" . $this->_getProductAttributeId('url_key'));

        $this->_productUrlFactory->create()->refreshRewrites();

        return true;
    }

    private function _dropFeatureResultTables()
    {
        $dbName = $this->_deploymentData['db']['connection']['default']['dbname'];
        $connection = $this->getConnection();
        $filterResultTablePrefix = $this->_getTableName('sinch_filter_result_');
        $resultTables = $this->_doQuery("SHOW TABLES LIKE '$filterResultTablePrefix%'")->fetch();

        if (!$resultTables) {
            return;
        }

        $dropSqls = [];
        $dropSqls[] = "SET GROUP_CONCAT_MAX_LEN=10000;";
        $dropSqls[] = "SET @tbls = (SELECT GROUP_CONCAT(TABLE_NAME)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME LIKE '$filterResultTablePrefix%');";
        $dropSqls[] = "SET @delStmt = CONCAT('DROP TABLE ',  @tbls);";
        $dropSqls[] = "PREPARE stmt FROM @delStmt;";
        $dropSqls[] = "EXECUTE stmt;";
        $dropSqls[] = "DEALLOCATE PREPARE stmt;";
        foreach ($dropSqls as $key => $dropSql) {
            $this->_doQuery($dropSql);
        }
    }

    private function _doQuery($query)
    {
        if ($this->debug_mode) {
            $this->_logImportInfo("Query: " . $query);
        }

        return $this->_connection->query($query);
    }

    protected function _getTableName($tableName = '')
    {
        if ($tableName) {
            return $this->_connection->getTableName($tableName);
        }

        return '';
    }

    protected function _checkCategoryBackupExist($catalog_category_entity_backup)
    {
        $backupData = $this->_doQuery("
            SELECT *
            FROM $catalog_category_entity_backup
        ")->fetch();

        if ($backupData) {
            return true;
        }

        return false;
    }

    protected function _logImportInfo($logString = '')
    {
        if ($logString) {
            $this->_sinchLogger->info($logString);
        }
    }

    protected function _setErrorMessage($message)
    {
        $this->_doQuery("
            UPDATE " . $this->import_status_statistic_table . "
            SET error_report_message=" . $this->_connection->quote($message) . "
            WHERE id=" . $this->current_import_status_statistic_id
        );
    }
}
