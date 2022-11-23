<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
	const SINCH_PRODUCT_TYPE_FREQUENCY_TABLE = 'sinch_product_type_frequency';
	
	private string $sinchProductTypesTable;
	private string $sinchProductFrequenciesTable;
	private string $sinchProductsTable;
	private string $sinchProductTypeFrequencyTable;
	private AdapterInterface $conn;
	
	/**
	 * @param \Magento\Framework\App\ResourceConnection       $resourceConn
	 * @param \Symfony\Component\Console\Output\ConsoleOutput $output
	 * @param \SITC\Sinchimport\Helper\Download               $downloadHelper
	 */
	public function __construct(
		ResourceConnection $resourceConn,
		ConsoleOutput $output,
		Download $downloadHelper
	)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
	
		$this->conn = $this->getConnection ();
	    $this->sinchProductTypesTable = $this->getTableName(self::SINCH_PRODUCT_TYPES_TABLE);
	    $this->sinchProductFrequenciesTable = $this->getTableName(self::SINCH_PRODUCT_FREQUENCIES_TABLE);
	    $this->sinchProductsTable = $this->getTableName(self::SINCH_PRODUCTS_TABLE);
	    $this->sinchProductTypeFrequencyTable = $this->getTableName(self::SINCH_PRODUCT_TYPE_FREQUENCY_TABLE);
    }
	
	/**
	 * @return array
	 */
	public function getRequiredFiles(): array
	{
		return [
			Download::FILE_PRODUCT_TYPES,
			Download::FILE_PRODUCT_FREQUENCIES,
			Download::FILE_PRODUCT_TYPE_FREQUENCY
		];
	}
	
	/**
	 * @return void
	 */
	public function parse()
	{
		$this->populateSinchProductTypes();
		$this->populateSinchProductFrequencies();
		$this->populateSinchProductTypeFrequency();
	}
	
	public function populateSinchProductTypes()
	{
		$parse_file = $this->dlHelper->getSavePath ( Download::FILE_PRODUCT_TYPES );
		$product_types_temp = $this->getTableName ( 'sinch_product_types_temp' );
		
		$this->log("Start parse " . Download::FILE_PRODUCT_TYPES);
		$this->startTimingStep('Create Product types temp table');
		$this->conn->query("DROP TABLE IF EXISTS {$product_types_temp}");
		$this->conn->query(
			"CREATE TABLE {$product_types_temp} (
                sinch_product_type_id int(11),
                sinch_product_type_name varchar(255)
                PRIMARY KEY('sinch_product_type_id' , 'sinch_product_type_id')
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
		);
		$this->endTimingStep();
		
		$this->startTimingStep('Load Product Types');
		$this->conn->query(
			"LOAD DATA LOCAL INFILE '{$parse_file}'
              INTO TABLE {$product_types_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_product_type_id, sinch_product_type_name)"
		);
		$this->conn->query("DROP TABLE IF EXISTS {$this->sinchProductTypesTable}");
		$this->conn->query("RENAME TABLE {$product_types_temp} TO {$this->sinchProductTypesTable}");
		$this->log("Finish parse " . Download::FILE_PRODUCT_TYPES);
		$this->endTimingStep();
		$this->timingPrint();
	}
	
	public function populateSinchProductFrequencies()
	{
		$parse_file = $this->dlHelper->getSavePath ( Download::FILE_PRODUCT_FREQUENCIES );
		$product_frequencies_temp = $this->getTableName ( 'sinch_product_frequencies_temp' );
		
		$this->log("Start parse " . Download::FILE_PRODUCT_FREQUENCIES);
		
		$this->startTimingStep('Create Product frequencies temp table');
		$this->conn->query("DROP TABLE IF EXISTS {$product_frequencies_temp}");
		$this->conn->query(
			"CREATE TABLE {$product_frequencies_temp} (
                sinch_product_frequency_id int(11),
                sinch_product_frequency_name varchar(255),
                PRIMARY KEY('sinch_product_frequency_id' , 'sinch_product_frequency_id')
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
		);
		$this->endTimingStep();
		
		$this->startTimingStep('Load Product Frequencies');
		$this->conn->query(
			"LOAD DATA LOCAL INFILE '{$parse_file}'
              INTO TABLE {$product_frequencies_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_product_frequency_id, sinch_product_frequency_name)"
		);
		$this->conn->query("DROP TABLE IF EXISTS {$this->sinchProductFrequenciesTable}");
		$this->conn->query("RENAME TABLE {$product_frequencies_temp} TO {$this->sinchProductFrequenciesTable}");
		$this->log("Finish parse " . Download::FILE_PRODUCT_FREQUENCIES);
		$this->endTimingStep();
		
		$this->timingPrint();
	}
	
	public function populateSinchProductTypeFrequency()
	{
		$parse_file = $this->dlHelper->getSavePath ( Download::FILE_PRODUCT_TYPE_FREQUENCY );
		$product_type_frequency_temp = $this->getTableName ( 'sinch_product_type_frequency_temp' );
		
		$this->log("Start parse " . Download::FILE_PRODUCT_TYPE_FREQUENCY);
		
		$this->startTimingStep('Create Product type frequency temp table');
		$this->conn->query("DROP TABLE IF EXISTS {$product_type_frequency_temp}");
		$this->conn->query(
			"CREATE TABLE {$product_type_frequency_temp} (
    			sinch_product_id int(11),
                sinch_product_type_id int(11),
                sinch_product_frequency_id int(11),
                FOREIGN KEY (`sinch_product_id`) REFERENCES `{$this->sinchProductsTable}` (`sinch_product_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`sinch_product_type_id`) REFERENCES `{$this->sinchProductTypesTable}` (`sinch_product_type_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                FOREIGN KEY (`sinch_product_frequency_id`) REFERENCES `{$this->sinchProductFrequenciesTable}` (`sinch_product_frequency_id`) ON DELETE CASCADE ON UPDATE CASCADE
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
		);
		$this->endTimingStep();
		
		$this->startTimingStep('Load Product Type Frequency');
		$this->conn->query(
			"LOAD DATA LOCAL INFILE '{$parse_file}'
              INTO TABLE {$product_type_frequency_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_product_id, sinch_product_type_id, sinch_product_frequency_id)"
		);
		$this->conn->query("DROP TABLE IF EXISTS {$this->sinchProductTypeFrequencyTable}");
		$this->conn->query("RENAME TABLE {$product_type_frequency_temp} TO {$this->sinchProductTypeFrequencyTable}");
		$this->log("Finish parse " . Download::FILE_PRODUCT_TYPE_FREQUENCY);
		$this->endTimingStep();
		
		$this->timingPrint();
	}
}