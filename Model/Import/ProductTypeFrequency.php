<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 *
 */
class ProductTypeFrequency extends AbstractImportSection {
	/**
	 *
	 */
	const LOG_PREFIX = "ProductTypeFrequency: ";
	/**
	 *
	 */
	const LOG_FILENAME = "product_type_frequency";
	
	
	const SINCH_PRODUCT_TYPES_TABLE = 'sinch_product_types';
	const SINCH_PRODUCT_FREQUENCIES_TABLE = 'sinch_product_frequencies';
	const SINCH_PRODUCTS_TABLE = 'sinch_products';
	
	private $productTypesTable;
	private $productFrequenciesTable;
	private $productsTable;
	
	/**
	 * @param \Magento\Framework\App\ResourceConnection       $resourceConn
	 * @param \Symfony\Component\Console\Output\ConsoleOutput $output
	 * @param \SITC\Sinchimport\Helper\Download               $downloadHelper
	 */
	public function __construct( ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
	
	    $this->productTypesTable = $this->getTableName(self::SINCH_PRODUCT_TYPES_TABLE);
	    $this->productFrequenciesTable = $this->getTableName(self::SINCH_PRODUCT_FREQUENCIES_TABLE);
	    $this->productsTable = $this->getTableName(self::SINCH_PRODUCTS_TABLE);
    }
	
	/**
	 * @return array
	 */
	public function getRequiredFiles(): array
	{
		return [Download::FILE_PRODUCT_TYPE_FREQUENCY];
	}
	
	/**
	 * @return void
	 */
	public function parse()
	{
		$parse_file = $this->dlHelper->getSavePath ( Download::FILE_PRODUCT_TYPE_FREQUENCY );
		$product_type_frequency_temp = $this->getTableName ( 'sinch_product_type_frequency_temp' );
		
		$conn = $this->getConnection();
		
		$this->log("Start parse " . Download::FILE_PRODUCT_TYPE_FREQUENCY);
		
		$this->startTimingStep('Create Product type frequency temp table');
		$conn->query("DROP TABLE IF EXISTS {$product_type_frequency_temp}");
		$conn->query(
			"CREATE TABLE {$product_type_frequency_temp} (
    			sinch_product_id int(11),
                sinch_product_type_id int(11),
                sinch_product_frequency_id int(11),
                FOREIGN KEY (`sinch_product_id`) REFERENCES `{$this->productsTable}` (`distributor_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`sinch_product_type_id`) REFERENCES `{$this->productTypesTable}` (`sinch_product_type_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`sinch_product_frequency_id`) REFERENCES `{$this->productFrequenciesTable}` (`sinch_product_frequency_id`) ON DELETE CASCADE ON UPDATE CASCADE
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
		);
		
		$this->endTimingStep();
		
		$this->startTimingStep('Load Product Type Frequency');
		$conn->query(
			"LOAD DATA LOCAL INFILE '{$parse_file}'
              INTO TABLE {$product_type_frequency_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_product_id, sinch_product_type_id, sinch_product_frequency_id)"
		);
		
		$sinch_product_type_frequency = $this->getTableName('sinch_product_type_frequency');
		$conn->query("DROP TABLE IF EXISTS {$sinch_product_type_frequency}");
		$conn->query("RENAME TABLE {$product_type_frequency_temp} TO {$sinch_product_type_frequency}");
		$this->log("Finish parse " . Download::FILE_PRODUCT_TYPE_FREQUENCY);
		$this->endTimingStep();
		$this->timingPrint();
	}
}