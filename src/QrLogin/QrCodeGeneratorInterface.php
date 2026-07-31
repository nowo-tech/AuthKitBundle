<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

/**
 * Generates a QR code image from a payload string.
 *
 * Default NullQrCodeGenerator returns null (text/URL fallback only).
 * Apps may install a real QR library and implement this interface.
 */
interface QrCodeGeneratorInterface
{
    /**
     * @return string|null Data URI (e.g. data:image/png;base64,...) or null for text fallback
     */
    public function generateDataUri(string $payload): ?string;
}
