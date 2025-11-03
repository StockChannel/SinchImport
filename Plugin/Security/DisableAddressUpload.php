<?php
declare(strict_types=1);

namespace SITC\Sinchimport\Plugin\Security;

use Magento\Customer\Controller\Address\File\Upload;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class DisableAddressUpload
{
    public function __construct(
        private readonly ResultFactory $resultFactory
    ) {}

    /**
     * Prevent file upload for customer addresses as it's the primary attack vector for SessionReaper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(Upload $_subject, callable $_proceed): ResultInterface
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData([
            'error' => (string)__('File uploads for customer address attributes are disabled.'),
            'errorcode' => 0,
        ]);

        return $result;
    }
}