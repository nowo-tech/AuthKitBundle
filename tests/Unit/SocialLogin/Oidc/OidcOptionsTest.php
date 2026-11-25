<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OidcOptionsTest extends TestCase
{
    public function testDefaults(): void
    {
        $options = new OidcOptions();
        self::assertSame(OidcOptions::SUPPORTED_ALGORITHMS, $options->allowedAlgorithms);
        self::assertSame(60, $options->clockSkew);
        self::assertSame(524288, $options->maxResponseBytes);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function refused(): iterable
    {
        yield 'empty' => [[]];
        yield 'none' => [['RS256', 'none']];
        yield 'hmac' => [['HS256']];
        yield 'unknown' => [['PS256']];
    }

    /**
     * @param list<string> $algorithms
     */
    #[DataProvider('refused')]
    public function testAllowListIsASubsetOfTheSupportedAlgorithms(array $algorithms): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OidcOptions($algorithms);
    }
}
