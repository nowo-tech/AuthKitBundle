<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function json_decode;

/**
 * Rate limits of the ceremony routes and `attestation: direct` (2.4.0).
 */
final class WebauthnStrictKernelTest extends WebauthnKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnStrictTestKernel::class;
    }

    #[Test]
    public function directAttestationNeedsAStatement(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $options = json_decode($this->json('/account/webauthn/register/options'), true);
        self::assertIsArray($options);
        self::assertSame('direct', $options['attestation']);

        $this->registerPasskey(new SoftwareAuthenticator());
        self::assertSame([], $this->passkeys()->passkeys($this->user('member@example.com')), 'A "none" statement is refused.');
        $this->registerPasskey(new SoftwareAuthenticator(), 'Key', ['fmt' => 'packed']);
        self::assertCount(1, $this->passkeys()->passkeys($this->user('member@example.com')), 'A packed self attestation verifies.');
        // Three registration options per window.
        self::assertSame(['error' => WebauthnFailure::RATE_LIMITED], json_decode($this->json('/account/webauthn/register/options', 429), true));
    }

    #[Test]
    public function theCeremonyRoutesAreRateLimitedPerClient(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey($authenticator, 'Key', ['fmt' => 'packed']);
        $this->client->getCookieJar()->clear();

        // A successful assertion gives its slot back; the third options of the window are refused.
        $this->client->setServerParameter('REMOTE_ADDR', '10.0.0.1');
        $this->passkeyLogin($authenticator);
        self::assertResponseRedirects('/home');
        $this->client->getCookieJar()->clear();
        $this->json('/login/webauthn/options');
        $this->json('/login/webauthn/options', 429);
        // Another client: one failed assertion per window.
        $this->client->setServerParameter('REMOTE_ADDR', '10.0.0.2');
        $this->passkeyLogin($authenticator, ['origin' => 'https://evil.example']);
        self::assertResponseRedirects('/login');
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $authenticator->assert($options)]));
        self::assertResponseRedirects('/login');
        self::assertSame([WebauthnFailure::RATE_LIMITED, WebauthnFailure::INVALID, WebauthnFailure::RATE_LIMITED], array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
    }

    #[Test]
    public function theSecondStepOptionsAndAssertionsAreRateLimited(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('admin@example.com', 'admin-password-123');
        $this->registerPasskey($authenticator, 'Key', ['fmt' => 'packed']);
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('REMOTE_ADDR', '10.0.0.3');
        $this->signIn('admin@example.com', 'admin-password-123');
        $page = $this->client->followRedirect();
        $this->json('/login/mfa/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form(['webauthn_mfa[credential]' => '{}']));
        $this->json('/login/mfa/webauthn/options');
        $this->json('/login/mfa/webauthn/options', 429);
        $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form(['webauthn_mfa[credential]' => '{}']));
        self::assertResponseRedirects('/login/mfa');
        $reasons = array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class));
        self::assertSame([WebauthnFailure::MALFORMED, WebauthnFailure::RATE_LIMITED, WebauthnFailure::RATE_LIMITED], $reasons);
    }
}
