<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialUsedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

/**
 * Audit ZBX-M1: with `rp_id: example.com` and no `allowed_origins`, an attacker who controls `evil.example.com` gets
 * the victim's passkey to sign his own challenge there (browsers allow a registrable-suffix RP id) and posts it with a
 * forged `Host: evil.example.com`. The expected origin is exactly `https://example.com`, never the request host, so the
 * assertion is refused; the same passkey signs in from the real origin.
 */
final class WebauthnParentDomainKernelTest extends WebauthnKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnParentDomainTestKernel::class;
    }

    #[Test]
    public function anAssertionFromASubDomainWithAForgedHostIsRefused(): void
    {
        $victim = new SoftwareAuthenticator();
        $this->client->setServerParameters(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']);
        $this->signedIn('member@example.com', 'member-password-123');
        $page    = $this->client->request('GET', '/account/webauthn');
        $options = $this->json('/account/webauthn/register/options');
        $this->client->submit($page->filter('form[name="webauthn_register"]')->form(['webauthn_register[credential]' => $victim->register($options, 'https://example.com')]));
        self::assertCount(1, $this->passkeys()->passkeys($this->user('member@example.com')));
        $this->client->getCookieJar()->clear();
        $this->events = [];

        // The attacker's own session on the forged host; the victim's passkey answers on https://evil.example.com.
        $this->client->setServerParameters(['HTTP_HOST' => 'evil.example.com', 'HTTPS' => 'on']);
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        self::assertStringContainsString('"rpId":"example.com"', $options);
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $victim->assert($options, 'https://evil.example.com', ['rp_id' => 'example.com'])]));
        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([WebauthnFailure::INVALID], array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
        self::assertSame([], $this->eventsOf(WebauthnCredentialUsedEvent::class));

        // The real origin signs in.
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameters(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']);
        $page    = $this->client->request('GET', '/login');
        $options = $this->json('/login/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_login"]')->form(['webauthn_login[credential]' => $victim->assert($options, 'https://example.com')]));
        self::assertCount(1, $this->eventsOf(WebauthnCredentialUsedEvent::class));
        self::assertStringStartsWith('member@example.com|', $this->whoAmI());
    }
}
