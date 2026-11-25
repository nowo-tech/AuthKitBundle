<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Webauthn;

use Nowo\AuthKitBundle\Webauthn\Base64Url;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Unpadded base64url, the stored labels and the failure messages (2.4.0).
 */
final class Base64UrlTest extends TestCase
{
    public function testItRoundTripsWithoutPadding(): void
    {
        foreach (['', "\0", "\xff\xfe", 'any bytes ?>', str_repeat("\x80", 33)] as $bytes) {
            $encoded = Base64Url::encode($bytes);
            self::assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);
            self::assertSame($bytes, Base64Url::decode($encoded));
        }
    }

    public function testItRefusesOtherAlphabetsAndLengths(): void
    {
        self::assertNull(Base64Url::decode('ab+c'));
        self::assertNull(Base64Url::decode('abc='));
        self::assertNull(Base64Url::decode('abcde'));
    }

    public function testLabelsAreCleanedAndCapped(): void
    {
        self::assertSame('Passkey', WebauthnManager::label(" \u{200B}\n "));
        self::assertSame('Work laptop', WebauthnManager::label("Work\tlaptop"));
        self::assertSame(str_repeat('é', 100), WebauthnManager::label(str_repeat('é', 120)));
    }

    public function testFailuresCarryTheirReason(): void
    {
        $failure = new WebauthnFailure(WebauthnFailure::LIMIT);
        self::assertSame('limit', $failure->reason);
        self::assertStringContainsString('(limit)', $failure->getMessage());
        self::assertSame('custom', (new WebauthnFailure(WebauthnFailure::USER, 'custom'))->getMessage());
    }
}
