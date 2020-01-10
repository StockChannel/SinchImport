<?php
namespace SITC\Sinchimport\Helper;

class Download extends \Magento\Framework\App\Helper\AbstractHelper
{
    /** @var \Symfony\Component\Console\Output\ConsoleOutput $output */
    private $output;
    /** @var \SITC\Sinchimport\Logger\Logger $logger */
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
        \Magento\Framework\App\Helper\Context $context,
        \Symfony\Component\Console\Output\ConsoleOutput $output,
        \SITC\Sinchimport\Logger\Logger $logger,
        \Magento\Framework\Filesystem\DirectoryList $dir
    ){
        parent::__construct($context);
        $this->output = $output;
        $this->logger = $logger;

        $this->saveDir = $dir->getPath(\Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR) . '/SITC/Sinchimport/';
        $ftp_data = $this->scopeConfig->getValue(
            'sinchimport/sinch_ftp',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        $this->username = $ftp_data['username'];
        $this->password = $ftp_data['password'];
        $this->server = $ftp_data['ftp_server'];
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
    public function downloadFile($file)
    {
        if($this->ftpConn == null) {
            $this->print("You aren't connected to Sinch FTP");
            return false;
        }

        $fileExists = false;
        $fileGzipped = false;
        $dirListing = \ftp_nlist($this->ftpConn, ".");
        if($dirListing === false) {
            $this->print("Warning: Directory listing failed, assuming file to exist (and not be gzipped)");
            $fileExists = true;
        } else {
            foreach($dirListing as $fileEntry){
                if($fileEntry == $file . ".gz") { //Detect whether the files seem to be gzipped
                    $fileGzipped = true;
                    $fileExists = true;
                    break;
                }
                if($fileEntry == $file) {
                    $fileExists = true;
                    break;
                }
            }
        }
        
        if(!$fileExists){
            return false;
        }
        $filePath = $file . ($fileGzipped ? ".gz" : "");
        $expectedSize = \ftp_size($this->ftpConn, $filePath);

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
            $state = ftp_nb_continue($this->ftpConn);
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
            $this->print("Attempting to gunzip CSV file");
            exec("gunzip " . $this->saveDir . $file . ".gz");
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

    private function wget($file)
    {
        $url = \escapeshellarg("ftp://{$this->username}:{$this->password}@{$this->server}/{$file}");
        $outputLoc = \escapeshellarg($this->saveDir . $file);
        exec("wget -O{$outputLoc} {$url}", null, $result = -1);
        return $result == 0;
    }
}