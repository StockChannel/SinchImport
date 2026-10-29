<?php
declare(strict_types=1);

namespace SITC\Sinchimport\Plugin\Security;

use Magento\Catalog\Model\Product\Option\Type\File as OptionFileType;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Frontend/adminhtml twin of DisableCartOptionFileUpload: block product custom-option file uploads
 * for all non-API flows (checkout add-to-cart, reconfigure, reorder, admin order create).
 *
 * Core path: AbstractType::collectProductOptions -> Option\Type\File::validateUserValue
 * -> ValidatorFile::validate -> Uploader->save(...) into pub/media/custom_options/quote.
 * Blocking here stops the upload write before it happens; matches the API-level block so no flow
 * can write into the custom_options tree (our hosts keep that directory non-writable as mitigation).
 */
class DisableFrontendOptionFileUpload
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param OptionFileType $_subject
     * @param callable $_proceed
     * @param mixed $values
     * @return OptionFileType
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundValidateUserValue(OptionFileType $_subject, callable $_proceed, $values): OptionFileType
    {
        $this->logger->warning('sinchimport: blocked product option file upload (front-end flow)', [
            'option_id' => $_subject->getOption()?->getId(),
        ]);

        throw new LocalizedException(__('File uploads for product custom options are disabled.'));
    }
}
