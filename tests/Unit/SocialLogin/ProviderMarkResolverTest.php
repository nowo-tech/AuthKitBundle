<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\ProviderMarkResolver;
use Nowo\AuthKitBundle\View\ProviderMark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function strtolower;

/**
 * 1.48: the brand mark of a provider button — the official artwork the bundle ships for a built-in provider, the
 * host's own `social_login.provider_marks` entry, or none.
 */
final class ProviderMarkResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function builtInProviders(): iterable
    {
        yield 'google' => ['google', 'img/social/google.svg'];
        yield 'github' => ['github', 'img/social/github.svg'];
        yield 'microsoft' => ['microsoft', 'img/social/microsoft.svg'];
        yield 'provider key is case-insensitive' => ['Google', 'img/social/google.svg'];
    }

    #[DataProvider('builtInProviders')]
    public function testBuiltInProvidersDefaultToTheShippedMark(string $provider, string $path): void
    {
        $mark = (new ProviderMarkResolver())->resolve($provider);

        self::assertInstanceOf(ProviderMark::class, $mark);
        self::assertSame($path, $mark->path);
        self::assertSame(ProviderMarkResolver::ASSET_PACKAGE, $mark->package);
        // Google's "G" must appear on a white background whatever colour the host gives the button.
        self::assertTrue($mark->onWhite);
        // 2.4.0: Google's backdrop is light-theme only (on_white: auto); the others keep it in both themes.
        self::assertSame(strtolower($provider) === 'google', $mark->onWhiteAuto);
    }

    /**
     * 2.4.0 `on_white: auto`: the backdrop in the light theme only. true / false keep their 1.48 meaning; an override
     * without `on_white` keeps the provider's default (auto for google).
     */
    public function testOnWhiteAuto(): void
    {
        $resolver = new ProviderMarkResolver();
        $auto     = $resolver->resolve('my_idp', ['my_idp' => ['path' => 'images/idp.svg', 'on_white' => 'auto']]);
        self::assertInstanceOf(ProviderMark::class, $auto);
        self::assertTrue($auto->onWhite);
        self::assertTrue($auto->onWhiteAuto);

        foreach ([true, false] as $value) {
            $mark = $resolver->resolve('google', ['google' => ['path' => 'images/g.svg', 'on_white' => $value]]);
            self::assertInstanceOf(ProviderMark::class, $mark);
            self::assertSame($value, $mark->onWhite);
            self::assertFalse($mark->onWhiteAuto, 'true / false stay as in 1.48.');
        }
        $github = $resolver->resolve('github', ['github' => ['path' => 'images/gh.svg']]);
        self::assertInstanceOf(ProviderMark::class, $github);
        self::assertFalse($github->onWhiteAuto);
        self::assertFalse((new ProviderMark('x.svg'))->onWhiteAuto);
    }

    public function testUnknownProviderHasNoMarkAndAppleIsNotShipped(): void
    {
        $resolver = new ProviderMarkResolver();

        self::assertNull($resolver->resolve('my_idp'));
        // Apple grants no permission to redistribute its logo: the bundle ships none (docs/BRAND-MARKS.md).
        self::assertNull($resolver->resolve('apple'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function removals(): iterable
    {
        yield 'false' => [['google' => ['path' => null]]];
        yield 'empty path' => [['google' => ['path' => '   ']]];
        yield 'key of another case' => [['GOOGLE' => ['path' => null]]];
    }

    /**
     * @param array<string, mixed> $marks
     */
    #[DataProvider('removals')]
    public function testHostRemovesABuiltInMark(array $marks): void
    {
        self::assertNull((new ProviderMarkResolver())->resolve('google', $marks));
    }

    public function testHostReplacesAMark(): void
    {
        $mark = (new ProviderMarkResolver())->resolve('google', [
            // Entries of other providers are skipped, not applied.
            'github' => ['path' => null],
            'google' => ['path' => 'images/g.svg', 'package' => 'app', 'on_white' => false],
        ]);

        self::assertInstanceOf(ProviderMark::class, $mark);
        self::assertSame('images/g.svg', $mark->path);
        self::assertSame('app', $mark->package);
        self::assertFalse($mark->onWhite);
    }

    /**
     * Without `on_white`, a replacement keeps what the bundle does for that provider: on white for a built-in one
     * (so a host that only swaps the file keeps a compliant Google button), off for a custom provider.
     */
    public function testOnWhiteDefaultsToTheBuiltInBehaviour(): void
    {
        $resolver = new ProviderMarkResolver();

        $google = $resolver->resolve('google', ['google' => ['path' => 'images/g.svg', 'package' => ' ']]);
        self::assertInstanceOf(ProviderMark::class, $google);
        self::assertTrue($google->onWhite);
        self::assertTrue($google->onWhiteAuto, 'A swapped Google file keeps the 2.4.0 default (auto).');
        self::assertNull($google->package, 'An empty package falls back to the host default package.');

        $custom = $resolver->resolve('my_idp', ['my_idp' => ['path' => 'images/idp.svg']]);
        self::assertInstanceOf(ProviderMark::class, $custom);
        self::assertFalse($custom->onWhite);
    }

    public function testAMarkNeedsAPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty asset path');

        new ProviderMark(' ');
    }

    public function testAMarkPackageCannotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        new ProviderMark('img/social/google.svg', ' ');
    }

    /**
     * REQ-RUNTIME-002: nothing is memoised, so two calls of the same resolver answer the current arguments.
     */
    public function testResolverKeepsNoStateBetweenCalls(): void
    {
        $resolver = new ProviderMarkResolver();

        self::assertInstanceOf(ProviderMark::class, $resolver->resolve('google'));
        self::assertNull($resolver->resolve('google', ['google' => ['path' => null]]));
        self::assertInstanceOf(ProviderMark::class, $resolver->resolve('google'));
    }

    /**
     * A non-map entry (a bare string reaches the resolver already normalised by the configuration) removes nothing
     * and is treated as "no mark", never as a crash.
     */
    public function testNonMapEntryIsTreatedAsNoMark(): void
    {
        self::assertNull((new ProviderMarkResolver())->resolve('google', ['google' => 'images/g.svg']));
    }
}
