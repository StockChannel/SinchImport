<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 *
 */
class ProductFrequencies extends AbstractImportSection {
	/**
	 *
	 */
	const LOG_PREFIX = "ProductFrequencies: ";
	/**
	 *
	 */
	const LOG_FILENAME = "product_frequencies";
	
	/**
	 * @param \Magento\Framework\App\ResourceConnection       $resourceConn
	 * @param \Symfony\Component\Console\Output\ConsoleOutput $output
	 * @param \SITC\Sinchimport\Helper\Download               $downloadHelper
	 */
	public function __construct( ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
    }
	
	/**
	 * @return array
	 */
	public function getRequiredFiles(): array
	{
		return [Download::FILE_PRODUCT_FREQUENCIES];
	}
	
	/**
	 * @return void
	 */
	public function parse()
	{
		$parse_file = $this->dlHelper->getSavePath ( Download::FILE_PRODUCT_FREQUENCIES );
		$product_frequencies_temp = $this->getTableName ( 'sinch_product_frequencies_temp' );
		
		$conn = $this->getConnection();
		
		$this->log("Start parse " . Download::FILE_PRODUCT_FREQUENCIES);
		
		$this->startTimingStep('Create Product frequencies temp table');
		$conn->query("DROP TABLE IF EXISTS {$product_frequencies_temp}");
		$conn->query(
			"CREATE TABLE {$product_frequencies_temp} (
                sinch_product_frequency_id int(11),
                sinch_product_frequency_name varchar(255),
                PRIMARY KEY('sinch_product_frequency_id' , 'sinch_product_frequency_id')
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
		);
		
		$this->endTimingStep();
		
		$this->startTimingStep('Load Product Frequencies');
		$conn->query(
			"LOAD DATA LOCAL INFILE '{$parse_file}'
              INTO TABLE {$product_frequencies_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_product_frequency_id, sinch_product_frequency_name)"
		);
		
		$sinch_product_frequencies = $this->getTableName('sinch_product_frequencies');
		$conn->query("DROP TABLE IF EXISTS {$sinch_product_frequencies}");
		$conn->query("RENAME TABLE {$product_frequencies_temp} TO {$sinch_product_frequencies}");
		$this->log("Finish parse " . Download::FILE_PRODUCT_FREQUENCIES);
		$this->endTimingStep();
		$this->timingPrint();
	}
}