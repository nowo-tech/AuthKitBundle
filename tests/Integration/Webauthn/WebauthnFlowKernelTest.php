<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialAddedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialUsedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use PHPUnit\Framework\Attributes\Test;

use function json_decode;

/**
 * Passkey registration and passwordless sign-in end to end (2.4.0).
 */
final class WebauthnFlowKernelTest extends WebauthnKernelTestCase
{
    #[Test]
    public function theLoginPageOffersPasskeysWithConditionalUiAndANoncedScript(): void
    {
        $page = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSame('username webauthn', $page->filter('input[name="login_form[_username]"]')->attr('autocomplete'));
        $root = $page->filter('[data-nowo-auth-kit-webauthn="login"]');
        self::assertCount(1, $root);
        self::assertSame('/login/webauthn/options', $root->attr('data-options-url'));
        self::assertSame('1', $root->attr('data-conditional'));
        self::assertCount(1, $page->filter('form[name="webauthn_login"] input[name="webauthn_login[credential]"]'));
        self::assertSame('test-nonce', $page->filter('script[src*="nowo-auth-kit-webauthn.js"]')->attr('nonce'));
    }

    #[Test]
    public function aPasskeyIsRegisteredAndSignsInWithoutAPassword(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $page = $this->registerPasskey($authenticator, '  Work  laptop  ');
        self::assertStringContainsString('Work laptop', $page->filter('[data-testid="auth-kit-webauthn-list"]')->text());
        self::assertSame('Work  laptop', $this->em()->getRepository(WebauthnCredential::class)->findAll()[0]->getLabel());
        self::assertCount(1, $this->eventsOf(WebauthnCredentialAddedEvent::class));
        $added = $this->eventsOf(WebauthnCredentialAddedEvent::class)[0];
        self::assertSame('member@example.com', $added->userId);
        self::assertTrue($added->synced);

        $options = json_decode($this->json('/account/webauthn/register/options'), true);
        self::assertIsArray($options);
        self::assertSame('required', $options['authenticatorSelection']['userVerification']);
        self::assertSame('required', $options['authenticatorSelection']['residentKey']);
        self::assertCount(1, $options['excludeCredentials']);
        self::assertSame('localhost', $options['rp']['name']);
        self::assertSame('localhost', $options['rp']['id']);

        $this->client->getCookieJar()->clear();
        $this->passkeyLogin($authenticator);
        self::assertResponseRedirects();
        self::assertStringStartsWith('member@example.com|', $this->whoAmI());
        self::assertStringNotContainsString('MfaPendingToken', $this->whoAmI());
        $used = $this->eventsOf(WebauthnCredentialUsedEvent::class);
        self::assertCount(1, $used);
        self::assertSame(WebauthnCredentialUsedEvent::CONTEXT_LOGIN, $used[0]->context);

        $stored = $this->em()->getRepository(WebauthnCredential::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(1, $stored[0]->getSignCount());
        self::assertNotNull($stored[0]->getLastUsedAt());
    }

    #[Test]
    public function anUnknownAndAForeignCredentialGetTheSameAnswer(): void
    {
        $member = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey($member);
        $this->client->getCookieJar()->clear();

        $this->passkeyLogin(new SoftwareAuthenticator());
        $unknown     = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Location')];
        $unknownPage = $this->client->followRedirect()->filter('[role="alert"], .nowo-ui-flash, [data-nowo-ui-flash]')->text('');

        // A real passkey of another account, answered with a user handle that is not its own.
        $this->client->getCookieJar()->clear();
        $this->passkeyLogin($member, ['user_handle' => 'someone-else']);
        $foreign     = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Location')];
        $foreignPage = $this->client->followRedirect()->filter('[role="alert"], .nowo-ui-flash, [data-nowo-ui-flash]')->text('');

        self::assertSame([303, '/login'], $unknown);
        self::assertSame($unknown, $foreign);
        self::assertSame($unknownPage, $foreignPage);
        $reasons = array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class));
        self::assertSame([WebauthnFailure::UNKNOWN_CREDENTIAL, WebauthnFailure::INVALID], $reasons);
        self::assertStringStartsWith('anonymous', $this->whoAmI());
    }
}
