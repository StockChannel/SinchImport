<?php
declare(strict_types=1);

namespace SITC\Sinchimport\Plugin\Security;

use Magento\Catalog\Model\Webapi\Product\Option\Type\File\Processor;
use Magento\Framework\Api\Data\ImageContentInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;

/**
 * Block API (REST/SOAP) uploads of cart file custom-options.
 *
 * Core path: GuestCartItemRepository::save -> CartItemPersister -> CartItemOptionsProcessor
 * -> CustomOptionProcessor -> CustomOption -> processFileContent() -> ImageProcessor::processImageContent()
 * writes the decoded upload into SYS_TMP (substr(md5(rand()), 0, 7) . '.' . client filename) BEFORE any
 * validation, and the catch block there logs failures without removing the staged copy. This is the
 * SessionReaper staging primitive observed on our fleet (tmpwatch cases Oct 2026: <7hex>.<name>.php in /tmp
 * on every rejected POST /rest/.../guest-carts/<id>/items carrying a base64 file custom-option).
 * Blocking here stops the write before it happens, for guest and customer carts, REST and SOAP.
 */
class DisableCartOptionFileUpload
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RemoteAddress $remoteAddress
    ) {}

    /**
     * @param Processor $_subject
     * @param callable $_proceed
     * @param ImageContentInterface $imageContent
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundProcessFileContent(
        Processor $_subject,
        callable $_proceed,
        ImageContentInterface $imageContent
    ): array {
        $this->logger->warning('sinchimport: blocked API cart file option upload attempt', [
            'ip' => (string) $this->remoteAddress->getRemoteAddress(),
            'name' => (string) $imageContent->getName(),
            'mime' => (string) $imageContent->getType(),
            'decoded_size' => strlen((string) base64_decode((string) $imageContent->getBase64EncodedData(), true)),
        ]);

        throw new InputException(__('File uploads for product custom options are disabled.'));
    }
}
