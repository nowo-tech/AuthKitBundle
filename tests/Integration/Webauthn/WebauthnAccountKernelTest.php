<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRemovedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRenamedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use Nowo\AuthKitBundle\Webauthn\WebauthnRemoval;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function json_decode;

/**
 * The passkey account page (2.4.0): recent sign-in, the passkey limit, rename, remove, the last-factor guard,
 * impersonation, foreign ids and CSRF.
 */
final class WebauthnAccountKernelTest extends WebauthnKernelTestCase
{
    #[Test]
    public function theAccountPageNeedsASignedInUser(): void
    {
        $this->client->request('GET', '/account/webauthn');
        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function changesNeedARecentSignIn(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey(new SoftwareAuthenticator());
        $page   = $this->client->request('GET', '/account/webauthn');
        $rename = $page->filter('[data-testid="auth-kit-webauthn-rename"]')->form();
        $delete = $page->filter('[data-testid="auth-kit-webauthn-delete"]')->form();
        $add    = $page->filter('form[name="webauthn_register"]')->form();

        $this->clock()->modify('+16 minutes');
        $page = $this->client->request('GET', '/account/webauthn');
        self::assertCount(1, $page->filter('[data-testid="auth-kit-webauthn-sign-in-again"]'));
        self::assertCount(0, $page->filter('form[name="webauthn_register"]'));
        self::assertSame(['error' => 'sign_in_again'], json_decode($this->json('/account/webauthn/register/options', 403), true));
        foreach ([$rename, $delete, $add] as $form) {
            $this->client->submit($form);
            self::assertResponseRedirects('/account/webauthn');
        }
        self::assertCount(1, $this->passkeys()->passkeys($this->user('member@example.com')));
        self::assertSame('Laptop', $this->passkeys()->passkeys($this->user('member@example.com'))[0]->label);
    }

    #[Test]
    public function thePasskeyLimitHolds(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        for ($i = 0; $i < 3; ++$i) {
            $page = $this->registerPasskey(new SoftwareAuthenticator(), 'Key ' . $i);
        }
        self::assertCount(1, $page->filter('[data-testid="auth-kit-webauthn-limit"]'));
        self::assertSame(['error' => WebauthnFailure::LIMIT], json_decode($this->json('/account/webauthn/register/options', 409), true));
        self::assertCount(3, $this->passkeys()->passkeys($this->user('member@example.com')));
    }

    #[Test]
    public function aRegistrationIsRefusedWithoutACredentialOrTwice(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $page = $this->client->request('GET', '/account/webauthn');
        $this->client->submit($page->filter('form[name="webauthn_register"]')->form());
        self::assertResponseRedirects('/account/webauthn');
        self::assertStringContainsString('The passkey could not be added.', $this->client->followRedirect()->html());

        $this->registerPasskey($authenticator);
        $this->registerPasskey($authenticator);
        self::assertCount(1, $this->passkeys()->passkeys($this->user('member@example.com')));
        self::assertSame([WebauthnFailure::DUPLICATE], array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
    }

    #[Test]
    public function aRegistrationFromAnotherOriginIsRefused(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $page = $this->registerPasskey(new SoftwareAuthenticator(), 'Key', ['origin' => 'https://evil.example']);
        self::assertCount(1, $page->filter('[data-testid="auth-kit-webauthn-none"]'));
        self::assertSame([WebauthnFailure::INVALID], array_map(static fn (WebauthnAuthenticationFailedEvent $e): string => $e->reason, $this->eventsOf(WebauthnAuthenticationFailedEvent::class)));
    }

    #[Test]
    public function aPasskeyIsRenamedAndRemoved(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $page   = $this->registerPasskey(new SoftwareAuthenticator());
        $id     = (string) $page->filter('[data-testid="auth-kit-webauthn-passkey"]')->attr('data-passkey');
        $rename = $page->filter('[data-testid="auth-kit-webauthn-rename"]')->form(['webauthn_rename_' . $id . '[label]' => "Phone\u{200B}\n"]);
        $this->client->submit($rename);
        self::assertResponseRedirects('/account/webauthn');
        self::assertSame('Phone', $this->passkeys()->passkeys($this->user('member@example.com'))[0]->label);
        // 2.5.0: one event after the write, with the label replaced and the stored one; never key material.
        $renamed = $this->eventsOf(WebauthnCredentialRenamedEvent::class);
        self::assertCount(1, $renamed);
        self::assertSame(['default', KernelUser::class, 'member@example.com', $id, 'Laptop', 'Phone'], [$renamed[0]->profile, $renamed[0]->userType, $renamed[0]->userId, $renamed[0]->publicId, $renamed[0]->oldLabel, $renamed[0]->newLabel]);

        // The same label (once cleaned) again: a success without a write or an event.
        $page = $this->client->request('GET', '/account/webauthn');
        $this->client->submit($page->filter('[data-testid="auth-kit-webauthn-rename"]')->form(['webauthn_rename_' . $id . '[label]' => ' Phone ']));
        self::assertStringContainsString('The passkey was renamed.', $this->client->followRedirect()->html());
        self::assertCount(1, $this->eventsOf(WebauthnCredentialRenamedEvent::class));

        $page = $this->client->request('GET', '/account/webauthn');
        $this->client->submit($page->filter('[data-testid="auth-kit-webauthn-delete"]')->form());
        self::assertResponseRedirects('/account/webauthn');
        self::assertCount(1, $this->client->followRedirect()->filter('[data-testid="auth-kit-webauthn-none"]'));
        $removed = $this->eventsOf(WebauthnCredentialRemovedEvent::class);
        self::assertSame([$id, 'Phone', WebauthnCredentialRemovedEvent::REASON_USER], [$removed[0]->publicId, $removed[0]->label, $removed[0]->reason]);
    }

    #[Test]
    public function anotherAccountsPasskeyCannotBeRenamedOrRemoved(): void
    {
        $this->signedIn('admin@example.com', 'admin-password-123');
        $page = $this->registerPasskey(new SoftwareAuthenticator());
        $id   = (string) $page->filter('[data-testid="auth-kit-webauthn-passkey"]')->attr('data-passkey');
        $this->client->getCookieJar()->clear();

        $this->signedIn('member@example.com', 'member-password-123');
        self::assertFalse($this->passkeys()->rename($this->user('member@example.com'), $id, 'Mine'));
        self::assertSame(WebauthnRemoval::Unknown, $this->passkeys()->remove($this->user('member@example.com'), $id));
        // Through the page: the forms are bound to the id, so a forged token is refused before anything is looked up.
        $this->client->request('POST', '/account/webauthn/' . $id . '/delete', ['csrf_only' => ['_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/account/webauthn/' . $id . '/rename', ['webauthn_rename_' . $id => ['label' => 'x', '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Laptop', $this->em()->getRepository(WebauthnCredential::class)->findAll()[0]->getLabel());
    }

    #[Test]
    public function unknownPasskeysAnswerAWarning(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $page   = $this->registerPasskey(new SoftwareAuthenticator());
        $id     = (string) $page->filter('[data-testid="auth-kit-webauthn-passkey"]')->attr('data-passkey');
        $rename = $page->filter('[data-testid="auth-kit-webauthn-rename"]')->form();
        $delete = $page->filter('[data-testid="auth-kit-webauthn-delete"]')->form();
        self::assertSame(WebauthnRemoval::Removed, $this->passkeys()->remove($this->user('member@example.com'), $id));
        $this->client->submit($rename);
        self::assertStringContainsString('That passkey does not exist.', $this->client->followRedirect()->html());
        $this->client->submit($delete);
        self::assertStringContainsString('That passkey does not exist.', $this->client->followRedirect()->html());
    }

    #[Test]
    public function theLastPasskeyOfARequiredSecondFactorStays(): void
    {
        $this->signedIn('admin@example.com', 'admin-password-123');
        $page = $this->registerPasskey(new SoftwareAuthenticator());
        $this->client->submit($page->filter('[data-testid="auth-kit-webauthn-delete"]')->form());
        self::assertStringContainsString('Your account needs a second factor', $this->client->followRedirect()->html());
        self::assertCount(1, $this->passkeys()->passkeys($this->user('admin@example.com')));

        // With TOTP on, the passkey may go.
        $this->enrol();
        $page = $this->client->request('GET', '/account/webauthn');
        $this->client->submit($page->filter('[data-testid="auth-kit-webauthn-delete"]')->form());
        self::assertSame([], $this->passkeys()->passkeys($this->user('admin@example.com')));
    }

    #[Test]
    public function passkeysCannotBeManagedWhileImpersonating(): void
    {
        $this->signedIn('support@example.com', 'support-password-123');
        $this->client->request('GET', '/mfa-home?_switch_user=member@example.com');
        $this->client->request('GET', '/account/webauthn');
        self::assertResponseStatusCodeSame(403);
    }
}
