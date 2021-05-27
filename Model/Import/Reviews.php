<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Class Reviews parses Reviews
 * @package SITC\Sinchimport\Model\Import
 */
class Reviews extends AbstractImportSection {
    const LOG_PREFIX = "Reviews: ";
    const LOG_FILENAME = "reviews";

    private string $reviewsTable;

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);

        $this->reviewsTable = $this->getTableName('sinch_reviews');
    }

    public function parse()
    {
        $this->createTableIfRequired();
        $conn = $this->getConnection();
        $reviewsCsv = $this->dlHelper->getSavePath(Download::FILE_REVIEWS);

        $this->startTimingStep('Load Reviews');
        $conn->query("DELETE FROM {$this->reviewsTable}");
        //Load the highest resolution award_image
        $conn->query(
            "LOAD DATA LOCAL INFILE '{$reviewsCsv}'
                INTO TABLE {$this->reviewsTable}
                FIELDS TERMINATED BY '|'
                OPTIONALLY ENCLOSED BY '\"'
                LINES TERMINATED BY \"\r\n\"
                IGNORE 1 LINES
                (id, score, date, url, author_name, comment, good, bad, bottom_line, review_site, @award_img_main, @award_img_80, @award_img_200)
                SET award_image = IF(
                    @award_img_main IS NOT NULL AND @award_img_main != '',
                    @award_img_main,
                    IF(
                        @award_img_200 IS NOT NULL AND @award_img_200 != '',
                        @award_img_200,
                        @award_img_80
                    )
                )"
        );
        $this->endTimingStep();

        $this->timingPrint();
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_REVIEWS];
    }

    private function createTableIfRequired()
    {
        //ID|Score|Date|URL|Author|Comment|Good|Bad|BottomLine|Site|AwardImageUrl|AwardImage80Url|AwardImage200Url
        //We only store a single award image (do we really need a link to lower res copies?)
        $this->getConnection()->query(
            "CREATE TABLE IF NOT EXISTS {$this->reviewsTable} (
                id int(10) NOT NULL PRIMARY KEY,
                score decimal(3, 4) NOT NULL DEFAULT 0.0,
                date timestamp NOT NULL DEFAULT NOW(),
                url varchar(1024),                    
                author_name varchar(100),
                comment varchar(1000),
                good varchar(1000),
                bad varchar(1000),
                bottom_line varchar(1000),
                review_site varchar(255),
                award_image varchar(1000)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_general_ci"
        );
    }
}