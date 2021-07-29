<?php
namespace SITC\Sinchimport\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Model\ScopeInterface;
use SITC\Sinchimport\Logger\Logger;
use Symfony\Component\Console\Output\ConsoleOutput;

class Download extends AbstractHelper
{
    public const FILE_ACCOUNT_GROUP_CATEGORIES = 'AccountGroupCategories.csv';
    public const FILE_ACCOUNT_GROUP_PRICE = 'AccountGroupPrices.csv';
    public const FILE_ACCOUNT_GROUPS = 'AccountGroups.csv';
    public const FILE_BRANDS = 'Brands.csv';
    public const FILE_BULLET_POINTS = 'BulletPoints.csv';
    public const FILE_CATEGORIES = 'Categories.csv';
    public const FILE_CATEGORIES_FEATURES = 'CategoryFeatures.csv';
    public const FILE_DISTRIBUTORS = 'Distributors.csv';
    public const FILE_DISTRIBUTORS_STOCK = 'DistributorStock.csv';
    public const FILE_FAMILIES = 'Families.csv';
    public const FILE_FAMILY_SERIES = 'FamilySeries.csv';
    public const FILE_MULTIMEDIA = 'Multimedia.csv';
    public const FILE_PRODUCTS_GALLERY_PICTURES = 'Pictures.csv';
    public const FILE_PRODUCT_CATEGORIES = 'ProductCategories.csv';
    public const FILE_PRODUCT_FEATURES = 'ProductFeatures.csv';
    public const FILE_PRODUCTS = 'Products.csv';
    public const FILE_REASONS_TO_BUY = 'ReasonsToBuy.csv';
    public const FILE_RELATED_PRODUCTS = 'RelatedProducts.csv';
    public const FILE_RESTRICTED_VALUES = 'RestrictedValues.csv';
    public const FILE_STOCK_AND_PRICES = 'StockAndPrices.csv';
    public const FILE_REVIEWS = 'Reviews.csv';

    private const EXPECTED_HEADER = [
        self::FILE_ACCOUNT_GROUP_CATEGORIES => 'AccountGroupID|CategoryID',
        self::FILE_ACCOUNT_GROUP_PRICE => 'AccountGroupID|ProductID|Price',
        self::FILE_ACCOUNT_GROUPS => 'ID|Name',
        self::FILE_BRANDS => 'ID|Name',
        self::FILE_BULLET_POINTS => 'ID|No|Value',
        self::FILE_CATEGORIES => 'ID|ParentID|Name|Order|IsHidden|ProductCount|SubCategoryProductCount|ThumbImageURL|NestLevel|SubCategoryCount|UNSPSC|TypeID|MainImageURL|MetaTitle|MetaDescription|Description|VirtualCategory',
        self::FILE_CATEGORIES_FEATURES => 'ID|CategoryID|Name|Order',
        self::FILE_DISTRIBUTORS => 'ID|Name',
        self::FILE_DISTRIBUTORS_STOCK => 'ProductID|DistributorID|Stock',
        self::FILE_FAMILIES => 'ID|ParentID|Name',
        self::FILE_FAMILY_SERIES => 'ID|Name',
        self::FILE_MULTIMEDIA => 'ID|ProductID|Description|URL|ContentType',
        self::FILE_PRODUCTS_GALLERY_PICTURES => 'ProductID|MainImageURL|ThumbImageURL',
        self::FILE_PRODUCT_CATEGORIES => 'ProductID|CategoryID',
        self::FILE_PRODUCT_FEATURES => 'ID|ProductID|RestrictedValueID',
        self::FILE_PRODUCTS => 'ID|Sku|Name|BrandID|MainImageURL|ThumbImageURL|Specifications|Description|DescriptionType|MediumImageURL|Title|Weight|ShortDescription|UNSPSC|EANCode|FamilyID|SeriesID|Score|ReleaseDate|EndOfLifeDate|LastYearSales|LastMonthSales|Searches|Feature1|Feature2|Feature3|Feature4',
        self::FILE_REASONS_TO_BUY => 'ID|No|Value|Title|HighPic',
        self::FILE_RELATED_PRODUCTS => 'ProductID|RelatedProductID',
        self::FILE_RESTRICTED_VALUES => 'ID|CategoryFeatureID|Text|Order',
        self::FILE_STOCK_AND_PRICES => 'ProductID|Stock|Price|Cost',
        self::FILE_REVIEWS => 'ID|Score|Date|URL|Author|Comment|Good|Bad|BottomLine|Site|AwardImageUrl|AwardImage80Url|AwardImage200Url'
    ];

    private ConsoleOutput $output;
    private Logger $logger;
    
    /** @var string $server The FTP server to use */
    private string $server;
    /** @var string $username The username to login to FTP as */
    private string $username;
    /** @var string $password The password for logging in to FTP */
    private string $password;

    /** @var resource $ftpConn The active FTP connection, if any */
    private $ftpConn = null;
    /** @var string $pendingLog Data waiting for newline to write to log */
    private string $pendingLog = "";
    /** @var string $saveDir The directory within var to save files to */
    private string $saveDir;

    public function __construct(
        Context $context,
        ConsoleOutput $output,
        Logger $logger,
        DirectoryList $dir
    ){
        parent::__construct($context);
        $this->output = $output;
        $this->logger = $logger->withName("Download");

        $this->saveDir = $dir->getPath(DirectoryList::VAR_DIR) . '/SITC/Sinchimport/';
        $ftp_data = $this->scopeConfig->getValue(
            'sinchimport/sinch_ftp',
            ScopeInterface::SCOPE_STORE
        );
        $this->username = $ftp_data['username'] ?? "";
        $this->password = $ftp_data['password'] ?? "";
        $this->server = $ftp_data['ftp_server'] ?? "";
    }

    /**
     * Create the import save directory if it doesn't exist (including any parents where necessary)
     * @throws LocalizedException when it fails to create it
     */
    public function createSaveDir()
    {
        if (!is_dir($this->saveDir)) {
            if (!mkdir($this->saveDir, 0777, true)) {
                throw new LocalizedException(__("Failed to create import directory. Check filesystem permissions"));
            }
        }
    }

    /**
     * Connects to the sinch FTP server ready for downloading files
     * Returns true on success, or an error message on failure
     * @return bool|string
     */
    public function connect()
    {
        if (empty($this->username) || empty($this->password)) {
            return 'FTP login or password has not been defined';
        }

        $this->ftpConn = \ftp_connect($this->server);
        if(!$this->ftpConn) {
            $this->ftpConn = null;
            return "Failed to connect to {$this->server}";
        }

        \ftp_set_option($this->ftpConn, FTP_TIMEOUT_SEC, 30);
        if (!@\ftp_login($this->ftpConn, $this->username, $this->password)) {
            $this->disconnect();
            return 'Incorrect username or password';
        }

        if(!\ftp_pasv($this->ftpConn, true)) { //Enable Passive mode
            $this->print("Warning: Passive mode failed to enable for {$this->server}");
        }
        return true;
    }

    /**
     * Downloads the file named by $file, saving it to var/SITC/Sinchimport/$file
     * Returns true on success, false on failure
     * @param string $file
     * @return bool
     */
    public function downloadFile(string $file): bool
    {
        if($this->ftpConn == null) {
            $this->print("You aren't connected to Sinch FTP");
            return false;
        }

        $fileExists = false;
        $fileGzipped = false;
        $dirListing = \ftp_nlist($this->ftpConn, ".");
        if($dirListing === false) {
            $this->print("Warning: Directory listing failed, attempting to reauthenticate");
            $this->disconnect();
            $conRes = $this->connect();
            if ($conRes !== true) {
                $this->print("FTP Reauthentication failed, abandoning");
                return false;
            }
            $dirListing = \ftp_nlist($this->ftpConn, ".");
            if ($dirListing === false) {
                $this->print("Warning: Secondary directory listing failed, assuming file to exist (and not be gzipped)");
                $fileExists = true;
            }
        }
        if (\is_array($dirListing)) {
            foreach ($dirListing as $fileEntry) {
                if ($fileEntry == $file . ".gz") { //Detect whether the files seem to be gzipped
                    $fileGzipped = true;
                    $fileExists = true;
                    break;
                }
                if ($fileEntry == $file) {
                    $fileExists = true;
                    //Don't break if we detect the regular file, as the gzipped version may exist (and it should be preferred)
                }
            }
        }

        if(!$fileExists){
            $this->print("$file doesn't exist");
            return false;
        }
        $filePath = $file . ($fileGzipped ? ".gz" : "");
        $expectedSize = \ftp_size($this->ftpConn, $filePath);

        //Ensure the files are removed prior to fresh download
        @unlink($this->saveDir . $file);
        @unlink($this->saveDir . $file . ".gz");

        $this->print("Downloading $filePath (" . ($expectedSize != -1 ? "$expectedSize bytes" : "unknown size") . ")...", false);
        $state = @\ftp_nb_get(
            $this->ftpConn,
            $this->saveDir . $filePath,
            $filePath,
            FTP_BINARY
        );

        $lastPrint = \time();
        while($state === FTP_MOREDATA) {
            $now = \time();
            if($now - $lastPrint >= 2) { //Print every other second
                $this->print(".", false);
                $lastPrint = $now;
            }
            $state = @\ftp_nb_continue($this->ftpConn);
        }

        if($state != FTP_FINISHED) {
            $this->print("failed");
            $this->print("Attempting fallback to wget for download of " . $filePath);
            if (!$this->wget($filePath)) {
                $this->print('Failed to download ' . $filePath . " from FTP server");
                return false;
            }
        } else {
            $this->print("done");
        }

        $actualSize = @\filesize($this->saveDir . $filePath);
        if($expectedSize > 0 && $expectedSize != $actualSize) {
            $this->print("Warning: File doesn't match expected size");
        }

        if($fileGzipped) {
            $this->print("Attempting to gunzip {$filePath}");
            $outputLoc = \escapeshellarg($this->saveDir . $file . ".gz");
            \exec("gunzip {$outputLoc}");
        }
        return true;
    }

    /**
     * Disconnect from the FTP server, cleaning up its connected resource
     * @return void
     */
    public function disconnect()
    {
        if($this->ftpConn != null){
            ftp_close($this->ftpConn);
            $this->ftpConn = null;
        }
    }

    /**
     * Return the save path for the given file
     * @param string $filename File to determine save location for
     * @return string Path to the file on disk (whether it exists or not)
     */
    public function getSavePath(string $filename): string
    {
        return $this->saveDir . $filename;
    }

    /**
     * Validate whether a file downloaded correctly (based on existence and file size)
     * and matches the expected format (based on the header row in the file)
     * @param string $filename
     * @return bool
     */
    public function validateFile(string $filename): bool
    {
        $saveFile = $this->saveDir . $filename;
        if (!file_exists($saveFile) || @filesize($saveFile) < 1) return false;

        //Read the header row from the given file and validate it matches the header we expect for it
        $fileHandle = fopen($saveFile, 'r');
        if ($fileHandle === false) {
            $this->print("Failed to open $filename for validation");
            return false;
        }
        $headerLine = fgets($fileHandle);
        fclose($fileHandle);
        if ($headerLine === false) {
            $this->print("Failed to read header line from $filename for validation");
            return false;
        }
        $headerLine = trim($headerLine);
        if ($headerLine != self::EXPECTED_HEADER[$filename]) {
            $this->print("Header line for file {$filename} doesn't match expected header: {$headerLine} != " . self::EXPECTED_HEADER[$filename]);
            return false;
        }
        return true;
    }

    private function print($message, $newline = true)
    {
        if($newline){
            $this->output->writeln($message);
            $this->logger->info($this->pendingLog . $message);
            $this->pendingLog = "";
        } else {
            $this->pendingLog .= $message;
            $this->output->write($message, false); //No newline, raw output
        }
    }

    private function wget($file): bool
    {
        $url = \escapeshellarg("ftp://{$this->server}/{$file}");
        $outputLoc = \escapeshellarg($this->saveDir . $file);
        $user = \escapeshellarg($this->username);
        $password = \escapeshellarg($this->password);
        $result = -1;
        $shellOut = "";
        \exec("wget -q -t 3 -T 30 --show-progress -O{$outputLoc} --user={$user} --password={$password} {$url}", $shellOut, $result);
        return $result == 0;
    }
}