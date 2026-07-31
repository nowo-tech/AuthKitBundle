<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Endroid\QrCode\Writer\PngWriter;
use Nowo\AuthKitBundle\QrLogin\EndroidQrCodeGenerator;
use PHPUnit\Framework\TestCase;

final class EndroidQrCodeGeneratorTest extends TestCase
{
    public function testReturnsNullWhenEndroidMissingOrEmptyPayload(): void
    {
        $generator = new EndroidQrCodeGenerator();
        self::assertNull($generator->generateDataUri(''));
    }

    public function testGeneratesPngDataUriWhenEndroidAvailable(): void
    {
        if (!class_exists(PngWriter::class)) {
            self::markTestSkipped('endroid/qr-code not installed');
        }

        $generator = new EndroidQrCodeGenerator();
        $uri       = $generator->generateDataUri('https://example.test/login/qr/approve?t=abc');

        self::assertNotNull($uri);
        self::assertMatchesRegularExpression('#^data:image/(png|svg\+xml);base64,#', $uri);
    }
}
