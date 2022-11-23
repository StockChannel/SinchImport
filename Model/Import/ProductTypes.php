<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use SITC\Sinchimport\Model\Sinch;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 *
 */
class ProductTypes extends AbstractImportSection {
	/**
	 *
	 */
	const LOG_PREFIX = "ProductTypes: ";
	/**
	 *
	 */
	const LOG_FILENAME = "product_types";
	
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
		return [Download::FILE_PRODUCT_TYPES];
	}
	
	/**
	 * @return void
	 */
	public function parse()
	{
		$parse_file = $this->dlHelper->getSavePath ( Download::FILE_PRODUCT_TYPES );
		$product_types_temp = $this->getTableName ( 'sinch_product_types_temp' );
		
		$conn = $this->getConnection();
		
		$this->log("Start parse " . Download::FILE_PRODUCT_TYPES);
		
		$this->startTimingStep('Create Product types temp table');
		$conn->query("DROP TABLE IF EXISTS {$product_types_temp}");
		$conn->query(
			"CREATE TABLE {$product_types_temp} (
                sinch_product_type_id int(11),
                sinch_product_type_name varchar(255)
                PRIMARY KEY('sinch_product_type_id' , 'sinch_product_type_id')
            )ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
		);
		
		$this->endTimingStep();
		
		$this->startTimingStep('Load Product Types');
		$conn->query(
			"LOAD DATA LOCAL INFILE '{$parse_file}'
              INTO TABLE {$product_types_temp}
              FIELDS TERMINATED BY '" . Sinch::FIELD_TERMINATED_CHAR . "'
              OPTIONALLY ENCLOSED BY '\"'
              LINES TERMINATED BY \"\r\n\"
              IGNORE 1 LINES
              (sinch_product_type_id, sinch_product_type_name)"
		);
		
		$sinch_product_types = $this->getTableName('sinch_product_types');
		$conn->query("DROP TABLE IF EXISTS {$sinch_product_types}");
		$conn->query("RENAME TABLE {$product_types_temp} TO {$sinch_product_types}");
		$this->log("Finish parse " . Download::FILE_PRODUCT_TYPES);
		$this->endTimingStep();
		$this->timingPrint();
	}
}