<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcDiscovery;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutHint;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

use function base64_encode;
use function hash_hkdf;
use function random_bytes;
use function sodium_crypto_aead_xchacha20poly1305_ietf_encrypt;
use function str_contains;
use function str_repeat;

use const SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

/**
 * 2.8.0: the sealed RP-initiated logout hint of the session.
 */
final class RpLogoutSessionTest extends TestCase
{
    private const SECRET = 'test-secret';

    public function testAHintIsSealedTakenOnceAndNeverStoredInClear(): void
    {
        $session = $this->session();
        $hints   = new RpLogoutSession(self::SECRET);
        $hints->remember($session, $this->hint());

        $stored = $session->get(RpLogoutSession::SESSION_KEY);
        self::assertIsString($stored);
        foreach (['header.payload.signature', 'ada@example.com', 'client-placeholder', 'idp.example.com'] as $clear) {
            self::assertFalse(str_contains($stored, $clear), $clear);
        }

        $hint = $hints->take($session);
        self::assertEquals($this->hint(), $hint);
        self::assertFalse($session->has(RpLogoutSession::SESSION_KEY), 'Taken: removed from the session.');
        self::assertNull($hints->take($session));
    }

    public function testForgetAndAnEmptySecret(): void
    {
        $session = $this->session();
        $hints   = new RpLogoutSession('');
        $hints->remember($session, $this->hint());
        self::assertEquals($this->hint(), $hints->take($session), 'An empty kernel.secret still works.');

        $hints->remember($session, $this->hint());
        $hints->forget($session);
        self::assertFalse($session->has(RpLogoutSession::SESSION_KEY));
    }

    public function testAnOversizedIdTokenIsNotKeptButTheHintIs(): void
    {
        $session = $this->session();
        $hints   = new RpLogoutSession(self::SECRET);
        $hints->remember($session, $this->hint(str_repeat('a', RpLogoutSession::MAX_ID_TOKEN_BYTES + 1)));
        self::assertNull($hints->take($session)?->idToken);

        $hints->remember($session, $this->hint(''));
        $hint = $hints->take($session);
        self::assertNotNull($hint);
        self::assertNull($hint->idToken);

        $hints->remember($session, $this->hint(null));
        self::assertNull($hints->take($session)?->idToken);
    }

    /**
     * @return iterable<string, array{RpLogoutHint}>
     */
    public static function outOfBounds(): iterable
    {
        $endpoint = 'https://idp.example.com/logout';
        yield 'empty provider' => [new RpLogoutHint('', 'c', 'u', $endpoint, null)];
        yield 'long provider' => [new RpLogoutHint(str_repeat('p', 65), 'c', 'u', $endpoint, null)];
        yield 'empty client id' => [new RpLogoutHint('okta', '', 'u', $endpoint, null)];
        yield 'long client id' => [new RpLogoutHint('okta', str_repeat('c', 256), 'u', $endpoint, null)];
        yield 'empty user' => [new RpLogoutHint('okta', 'c', '', $endpoint, null)];
        yield 'long user' => [new RpLogoutHint('okta', 'c', str_repeat('u', RpLogoutSession::MAX_USER_IDENTIFIER_BYTES + 1), $endpoint, null)];
        yield 'empty endpoint' => [new RpLogoutHint('okta', 'c', 'u', '', null)];
        yield 'long endpoint' => [new RpLogoutHint('okta', 'c', 'u', $endpoint . str_repeat('x', OidcDiscovery::MAX_OPTIONAL_ENDPOINT_LENGTH), null)];
    }

    #[DataProvider('outOfBounds')]
    public function testAHintOutOfBoundsIsNotKeptAndDropsThePreviousOne(RpLogoutHint $hint): void
    {
        $session = $this->session();
        $hints   = new RpLogoutSession(self::SECRET);
        $hints->remember($session, $this->hint());
        $hints->remember($session, $hint);

        self::assertFalse($session->has(RpLogoutSession::SESSION_KEY));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadable(): iterable
    {
        yield 'not a string' => [['p' => 'okta']];
        yield 'not base64' => ['%%%'];
        yield 'too short' => [base64_encode('short')];
        yield 'tampered' => [base64_encode(str_repeat("\0", 64))];
        yield 'another key' => [self::seal('{"p":"okta","c":"c","u":"u","e":"https://idp.example.com/logout","t":null}', 'other-secret')];
        yield 'not json' => [self::seal('not json')];
        yield 'too deep' => [self::seal('{"p":["okta"]}')];
        yield 'not an object' => [self::seal('"okta"')];
        yield 'missing parts' => [self::seal('{"p":"okta","c":"c","u":"u"}')];
        yield 'wrong types' => [self::seal('{"p":"okta","c":1,"u":"u","e":"https://idp.example.com/logout"}')];
    }

    /**
     * SECURITY: a value planted in the session, copied from another application or tampered with is no hint.
     */
    #[DataProvider('unreadable')]
    public function testAnUnreadableValueIsNoHintAndIsRemoved(mixed $value): void
    {
        $session = $this->session();
        $session->set(RpLogoutSession::SESSION_KEY, $value);

        self::assertNull((new RpLogoutSession(self::SECRET))->take($session));
        self::assertFalse($session->has(RpLogoutSession::SESSION_KEY));
    }

    public function testANonStringIdTokenIsDropped(): void
    {
        $session = $this->session();
        $session->set(RpLogoutSession::SESSION_KEY, self::seal('{"p":"okta","c":"c","u":"u","e":"https://idp.example.com/logout","t":7}'));

        $hint = (new RpLogoutSession(self::SECRET))->take($session);
        self::assertNotNull($hint);
        self::assertNull($hint->idToken);
    }

    private static function seal(string $plain, string $secret = self::SECRET): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key   = hash_hkdf('sha256', 'nowo-auth-kit-rp-logout|' . $secret, 32, 'nowo-auth-kit-rp-logout-seal');

        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, 'nowo-auth-kit-rp-logout|v1', $nonce, $key));
    }

    private function hint(?string $idToken = 'header.payload.signature'): RpLogoutHint
    {
        return new RpLogoutHint('okta', 'client-placeholder', 'ada@example.com', 'https://idp.example.com/logout', $idToken);
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
