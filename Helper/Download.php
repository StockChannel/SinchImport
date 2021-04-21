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
    public const FILE_CATEGORIES = 'Categories.csv';
    public const FILE_CATEGORIES_FEATURES = 'CategoryFeatures.csv';
    public const FILE_DISTRIBUTORS = 'Distributors.csv';
    public const FILE_DISTRIBUTORS_STOCK = 'DistributorStock.csv';
    public const FILE_MANUFACTURERS = 'Manufacturers.csv';
    public const FILE_PRODUCT_FEATURES = 'ProductFeatures.csv';
    public const FILE_PRODUCT_CATEGORIES = 'ProductCategories.csv';
    public const FILE_PRODUCTS = 'Products.csv';
    public const FILE_RELATED_PRODUCTS = 'RelatedProducts.csv';
    public const FILE_RESTRICTED_VALUES = 'RestrictedValues.csv';
    public const FILE_STOCK_AND_PRICES = 'StockAndPrices.csv';
    public const FILE_PRODUCTS_GALLERY_PICTURES = 'Pictures.csv';
    public const FILE_ACCOUNT_GROUP_CATEGORIES = 'AccountGroupCategories.csv';
    public const FILE_ACCOUNT_GROUPS = 'AccountGroups.csv';
    public const FILE_ACCOUNT_GROUP_PRICE = 'AccountGroupPrices.csv';

    /** @var ConsoleOutput $output */
    private $output;
    /** @var Logger $logger */
    private $logger;

    
    /** @var string $server The FTP server to use */
    private $server;
    /** @var string $username The username to login to FTP as */
    private $username;
    /** @var string $password The password for logging in to FTP */
    private $password;

    /** @var resource $ftpConn The active FTP connection, if any */
    private $ftpConn = null;
    /** @var string $pendingLog Data waiting for newline to write to log */
    private $pendingLog = "";
    /** @var string $saveDir The directory within var to save files to */
    private $saveDir;

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
        $this->username = isset($ftp_data['username']) ? $ftp_data['username'] : "";
        $this->password = isset($ftp_data['password']) ? $ftp_data['password'] : "";
        $this->server = isset($ftp_data['ftp_server']) ? $ftp_data['ftp_server'] : "";
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
        if (!$this->validateFile($file)) {
            $this->print("File downloaded correctly but failed to pass validation");
            return false;
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
     * Return the required files for the given import mode
     * @param bool $fullImport Full import if true, otherwise stock price
     * @return string[] The required filenames
     */
    public function getRequiredFilenames(bool $fullImport): array
    {
        if ($fullImport) {
            return [
                FILE_CATEGORIES,
                FILE_CATEGORY_TYPES,
                FILE_CATEGORIES_FEATURES,
                FILE_DISTRIBUTORS,
                FILE_DISTRIBUTORS_STOCK,
                FILE_EANCODES,
                FILE_MANUFACTURERS,
                FILE_PRODUCT_FEATURES,
                FILE_PRODUCT_CATEGORIES,
                FILE_PRODUCTS,
                FILE_RELATED_PRODUCTS,
                FILE_RESTRICTED_VALUES,
                FILE_STOCK_AND_PRICES,
                FILE_PRODUCTS_GALLERY_PICTURES,
                FILE_PRICE_RULES,
                FILE_PRODUCT_CONTRACTS,
                FILE_ACCOUNT_GROUP_CATEGORIES,
                FILE_ACCOUNT_GROUPS,
                FILE_ACCOUNT_GROUP_PRICE
            ];
        }
        return [
            FILE_STOCK_AND_PRICES,
            FILE_PRICE_RULES,
            FILE_ACCOUNT_GROUPS,
            FILE_ACCOUNT_GROUP_CATEGORIES,
            FILE_ACCOUNT_GROUP_PRICE,
            FILE_DISTRIBUTORS,
            FILE_DISTRIBUTORS_STOCK
        ];
    }

    /**
     * Return the optional files for the given import mode
     * @param bool $fullImport Full import if true, otherwise stock price
     * @return string[] The optional filenames
     */
    public function getOptionalFilenames(bool $fullImport): array
    {
        if ($fullImport) {
            return [
                FILE_CATEGORIES_FEATURES,
                FILE_CATEGORY_TYPES,
                FILE_ACCOUNT_GROUP_CATEGORIES,
                FILE_DISTRIBUTORS_STOCK,
                FILE_RESTRICTED_VALUES,
                FILE_PRICE_RULES,
                FILE_PRODUCT_FEATURES,
                FILE_RELATED_PRODUCTS,
                FILE_PRODUCT_CONTRACTS
            ];
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
    private function validateFile(string $filename): bool
    {
        $saveFile = $this->saveDir . $filename;
        if (!file_exists($saveFile) || @filesize($saveFile) < 1) return false;

        //TODO: Header validation
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