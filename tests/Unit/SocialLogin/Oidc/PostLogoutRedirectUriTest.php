<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\PostLogoutRedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function str_repeat;

/**
 * 2.8.0: the `post_logout_redirect_uri` comes from the host's allow-list only.
 */
final class PostLogoutRedirectUriTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'not a string' => [7];
        yield 'empty' => [''];
        yield 'too long' => ['/' . str_repeat('a', PostLogoutRedirectUri::MAX_LENGTH)];
        yield 'white space' => ['/signed out'];
        yield 'control character' => ["/bye\r\n"];
        yield 'backslash' => ['/\\evil.example'];
        yield 'protocol-relative' => ['//evil.example/bye'];
        yield 'path with fragment' => ['/bye#x'];
        yield 'relative path' => ['bye'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'other scheme' => ['ftp://app.example.com/bye'];
        yield 'unparsable' => ['https:///bye'];
        yield 'credentials' => ['https://user@app.example.com/bye'];
        yield 'fragment' => ['https://app.example.com/bye#x'];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntriesAreRefused(mixed $entry): void
    {
        self::assertNotNull(PostLogoutRedirectUri::entryError($entry));
    }

    public function testValidEntries(): void
    {
        foreach (['/', '/signed-out?x=1', 'https://app.example.com/bye', 'http://localhost:8000/'] as $entry) {
            self::assertNull(PostLogoutRedirectUri::entryError($entry), $entry);
        }
    }

    public function testTheSignOutTargetWinsOnlyWhenListed(): void
    {
        $request = Request::create('https://app.example.com/logout');
        $allowed = ['/signed-out', 'https://app.example.com/'];

        self::assertSame('https://app.example.com/', PostLogoutRedirectUri::choose($allowed, 'https://app.example.com/', $request));
        self::assertSame('https://app.example.com/signed-out', PostLogoutRedirectUri::choose($allowed, '/signed-out', $request), 'A path target is resolved on this origin.');
        // Not listed: the first entry, never the target.
        self::assertSame('https://app.example.com/signed-out', PostLogoutRedirectUri::choose($allowed, 'https://evil.example/', $request));
        self::assertSame('https://app.example.com/signed-out', PostLogoutRedirectUri::choose($allowed, '//evil.example/', $request));
        self::assertSame('https://app.example.com/signed-out', PostLogoutRedirectUri::choose($allowed, null, $request));
        self::assertSame('https://app.example.com/signed-out', PostLogoutRedirectUri::choose($allowed, '', $request));
    }

    public function testAnEmptyOrInvalidListSendsNone(): void
    {
        $request = Request::create('https://app.example.com/logout');
        self::assertNull(PostLogoutRedirectUri::choose([], 'https://app.example.com/', $request));
        self::assertNull(PostLogoutRedirectUri::choose(['//evil.example/'], 'https://app.example.com/', $request));
    }
}
