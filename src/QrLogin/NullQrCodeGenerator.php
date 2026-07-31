<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

/**
 * Returns null for QR data URI — text/URL fallback only (no hard QR library dependency).
 */
final class NullQrCodeGenerator implements QrCodeGeneratorInterface
{
    public function generateDataUri(string $payload): ?string
    {
        return null;
    }
}
