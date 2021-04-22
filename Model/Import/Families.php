<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class Families extends AbstractImportSection {

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
    }

    public function getRequiredFiles(): array
    {
        return [
            Download::FILE_FAMILIES,
            Download::FILE_FAMILY_SERIES
        ];
    }

    public function parse()
    {
        // TODO: Implement parse() method.
    }
}