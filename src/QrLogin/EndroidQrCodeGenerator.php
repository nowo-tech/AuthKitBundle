<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

use function extension_loaded;
use function str_starts_with;

/**
 * Optional endroid/qr-code image generator (PNG when ext-gd is available, otherwise SVG).
 *
 * Soft dependency: when the library is not installed this returns null (same as Null).
 * Prefer installing {@see https://packagist.org/packages/endroid/qr-code endroid/qr-code} ^6.
 */
final class EndroidQrCodeGenerator implements QrCodeGeneratorInterface
{
    public function generateDataUri(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        if (!class_exists(Builder::class)
            || !class_exists(PngWriter::class)
            || !class_exists(SvgWriter::class)
        ) {
            return null; // @codeCoverageIgnore
        }

        try {
            // CI Docker image installs ext-gd (PNG). SVG remains the runtime fallback without gd.
            $writer = extension_loaded('gd')
                ? new PngWriter()
                : new SvgWriter(); // @codeCoverageIgnore

            $builder = new Builder(
                writer: $writer,
                data: $payload,
                size: 280,
                margin: 10,
            );
            $uri = $builder->build()->getDataUri();

            return str_starts_with($uri, 'data:image/') ? $uri : null;
        } catch (Throwable) { // @codeCoverageIgnoreStart
            return null;
        } // @codeCoverageIgnoreEnd
    }
}
