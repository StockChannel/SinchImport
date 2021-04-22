<?php
namespace SITC\Sinchimport\Model\Import;

use Magento\Framework\App\ResourceConnection;
use SITC\Sinchimport\Helper\Download;
use Symfony\Component\Console\Output\ConsoleOutput;

class BulletPoints extends AbstractImportSection {

    public function __construct(ResourceConnection $resourceConn, ConsoleOutput $output, Download $downloadHelper)
    {
        parent::__construct($resourceConn, $output, $downloadHelper);
    }

    public function getRequiredFiles(): array
    {
        return [Download::FILE_BULLET_POINTS];
    }

    public function parse()
    {
        // TODO: Implement parse() method.
    }
}