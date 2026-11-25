<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialUsedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

/**
 * Every check of the sign-in ceremony refuses with the same answer (2.4.0): origin, RP id, user verification, user
 * presence, ceremony type, challenge (missing, foreign, reused, expired), counter (replay / clone), malformed input.
 */
final class WebauthnCeremonyKernelTest extends WebauthnKernelTestCase
{
    private SoftwareAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey($this->authenticator);
        $this->client->getCookieJar()->clear();
        $this->events = [];
    }

    /**
     * @return iterable<string, array{0: array{origin?: string, rp_id?: string, flags?: int, user_handle?: string|null, type?: string, challenge?: string}, 1: string}>
     */
    public static function brokenAssertions(): iterable
    {
        yield 'another origin' => [['origin' => 'https://evil.example'], WebauthnFailure::INVALID];
        yield 'a subdomain origin' => [['origin' => 'http://sub.localhost'], WebauthnFailure::INVALID];
        yield 'another RP id' => [['rp_id' => 'evil.example'], WebauthnFailure::INVALID];
        yield 'no user verification' => [['flags' => SoftwareAuthenticator::FLAG_UP], WebauthnFailure::INVALID];
        yield 'no user presence' => [['flags' => SoftwareAuthenticator::FLAG_UV], WebauthnFailure::INVALID];
        yield 'a registration type' => [['type' => 'webauthn.create'], WebauthnFailure::INVALID];
        yield 'another challenge' => [['challenge' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'], WebauthnFailure::INVALID];
        yield 'no user handle' => [['user_handle' => null], WebauthnFailure::INVALID];
    }

    /**
     * @param array{origin?: string, rp_id?: string, flags?: int, user_handle?: string|null, type?: string, challenge?: string} $tweaks
     */
    #[Test]
    #[DataProvider('brokenAssertions')]
    public function aBrokenAssertionIsRefused(array $tweaks, string $reason): void
    {
        $this->passkeyLogin($this->authenticator, $tweaks);
        $this->assertRefused([$reason]);
    }

    #[Test]
    public function aChallengeIsSingleUse(): void
    {
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $bad     = $page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $this->authenticator->assert($options, 'https://evil.example')]);
        $this->client->submit($bad);
        // The same challenge again, now correctly signed: the first attempt used it up.
        $good = $page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $this->authenticator->assert($options)]);
        $this->client->submit($good);
        $this->assertRefused([WebauthnFailure::INVALID, WebauthnFailure::CHALLENGE]);
    }

    #[Test]
    public function anExpiredOrMissingChallengeIsRefused(): void
    {
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->clock()->modify('+91 seconds');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $this->authenticator->assert($options)]));
        // No options asked in this session.
        $this->client->getCookieJar()->clear();
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $this->authenticator->assert($options)]));
        $this->assertRefused([WebauthnFailure::CHALLENGE, WebauthnFailure::CHALLENGE]);
    }

    #[Test]
    public function aCounterThatDoesNotMoveIsRefused(): void
    {
        $this->passkeyLogin($this->authenticator);
        self::assertResponseRedirects('/home');
        $this->client->getCookieJar()->clear();
        $this->events = [];
        $this->passkeyLogin($this->authenticator, ['counter' => 1]);
        $this->assertRefused([WebauthnFailure::COUNTER]);
    }

    #[Test]
    public function anAuthenticatorWithoutCounterSignsInEveryTime(): void
    {
        $zero = new SoftwareAuthenticator(0, false);
        $this->signedIn('admin@example.com', 'admin-password-123');
        $this->registerPasskey($zero);
        for ($i = 0; $i < 2; ++$i) {
            $this->client->getCookieJar()->clear();
            $this->passkeyLogin($zero);
            self::assertResponseRedirects('/home');
            self::assertStringStartsWith('admin@example.com|', $this->whoAmI());
        }
        self::assertCount(2, $this->eventsOf(WebauthnCredentialUsedEvent::class));
    }

    #[Test]
    public function malformedOrMissingCredentialsAreRefused(): void
    {
        $page = $this->client->request('GET', '/login');
        $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => '{"id":"x"}']));
        self::assertResponseRedirects('/login');
        // A registration response where an assertion is expected.
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => (new SoftwareAuthenticator())->register('{"challenge":"' . json_decode($options, true)['challenge'] . '","rp":{"id":"localhost"},"user":{"id":"AA"}}')]));
        self::assertResponseRedirects('/login');
        // The script did not run: no ceremony, no event.
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form());
        self::assertResponseRedirects('/login');
        $this->assertRefused([WebauthnFailure::MALFORMED, WebauthnFailure::MALFORMED]);
    }

    #[Test]
    public function aSignedInBrowserIsSentBack(): void
    {
        $this->passkeyLogin($this->authenticator);
        $this->client->followRedirect();
        $this->client->request('POST', '/login/webauthn/assert');
        self::assertResponseRedirects('/login');
    }

    /**
     * @param list<string> $reasons
     */
    private function assertRefused(array $reasons): void
    {
        self::assertResponseRedirects('/login');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('The passkey sign-in failed.', (string) $this->client->getResponse()->getContent());
        self::assertSame($reasons, array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
        self::assertCount(0, $this->eventsOf(WebauthnCredentialUsedEvent::class));
        self::assertGreaterThan(0, $page->count());
        self::assertStringStartsWith('anonymous', $this->whoAmI());
    }
}
