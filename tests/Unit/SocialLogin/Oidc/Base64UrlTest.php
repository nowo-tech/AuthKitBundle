<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Base64UrlTest extends TestCase
{
    public function testRoundTripWithoutPadding(): void
    {
        $encoded = Base64Url::encode("\xfb\xff\xfe-binary");
        self::assertStringNotContainsString('=', $encoded);
        self::assertStringNotContainsString('+', $encoded);
        self::assertStringNotContainsString('/', $encoded);
        self::assertSame("\xfb\xff\xfe-binary", Base64Url::decode($encoded));
        self::assertSame('', Base64Url::decode(''));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'padding' => ['YQ=='];
        yield 'standard alphabet' => ['a+b/'];
        yield 'white space' => ['YW Jj'];
        yield 'impossible length' => ['YWJjZ'];
    }

    #[DataProvider('invalid')]
    public function testStrictDecodeRefuses(string $input): void
    {
        self::assertNull(Base64Url::decode($input));
    }
}
