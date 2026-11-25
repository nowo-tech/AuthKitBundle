<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Mfa\Event\MfaChallengePassedEvent;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnAuthenticationFailedEvent;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialUsedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;
use PHPUnit\Framework\Attributes\Test;

use function json_decode;

/**
 * A passkey as the second factor next to TOTP (2.4.0): it satisfies a required second factor, it is offered on the
 * challenge page, only the pending user's passkeys count, it shares the attempt budget, and a passkey sign-in is
 * exempt from the second step by default.
 */
final class WebauthnMfaKernelTest extends WebauthnKernelTestCase
{
    private SoftwareAuthenticator $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // The administrator (MFA required, inside the grace period) registers a passkey and no TOTP.
        $this->admin = new SoftwareAuthenticator();
        $this->signedIn('admin@example.com', 'admin-password-123');
        $this->registerPasskey($this->admin);
        $this->client->getCookieJar()->clear();
        $this->events = [];
    }

    #[Test]
    public function aPasskeyIsTheSecondStepOfAPasswordSignIn(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/login/mfa');
        $page = $this->client->followRedirect();
        self::assertCount(0, $page->filter('form[name="mfa_code_form"]'), 'No TOTP: no code form.');
        $root = $page->filter('[data-nowo-auth-kit-webauthn="mfa"]');
        self::assertSame('/login/mfa/webauthn/options', $root->attr('data-options-url'));
        self::assertSame('test-nonce', $page->filter('script[src*="nowo-auth-kit-webauthn.js"]')->attr('nonce'));

        $options = $this->json('/login/mfa/webauthn/options');
        $decoded = json_decode($options, true);
        self::assertIsArray($decoded);
        self::assertCount(1, $decoded['allowCredentials']);
        self::assertSame('required', $decoded['userVerification']);

        $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form(['webauthn_mfa[credential]' => $this->admin->assert($options)]));
        self::assertResponseRedirects();
        self::assertStringStartsWith('admin@example.com|', $this->whoAmI());
        self::assertStringNotContainsString('Pending', $this->whoAmI());
        self::assertSame(WebauthnCredentialUsedEvent::CONTEXT_MFA, $this->eventsOf(WebauthnCredentialUsedEvent::class)[0]->context);
        self::assertSame('webauthn', $this->eventsOf(MfaChallengePassedEvent::class)[0]->method);
    }

    #[Test]
    public function aPasskeySatisfiesTheRequirementAfterTheGracePeriod(): void
    {
        $this->clock()->modify('+8 days');
        $this->signIn('admin@example.com', 'admin-password-123');
        self::assertResponseRedirects('/login/mfa', null, 'The challenge, not the forced enrolment.');
        self::assertFalse($this->manager()->mustEnrolNow($this->user('admin@example.com')));
        self::assertTrue($this->manager()->hasSecondFactor($this->user('admin@example.com')));
        self::assertFalse($this->manager()->isEnabled($this->user('admin@example.com')));
    }

    #[Test]
    public function aPasskeySignInIsExemptFromTheSecondStep(): void
    {
        $this->passkeyLogin($this->admin);
        self::assertResponseRedirects('/home');
        self::assertStringStartsWith('admin@example.com|', $this->whoAmI());
    }

    #[Test]
    public function anotherAccountsPasskeyIsRefusedAtTheSecondStep(): void
    {
        $member = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->registerPasskey($member);
        $this->client->getCookieJar()->clear();
        $this->events = [];

        $this->signIn('admin@example.com', 'admin-password-123');
        $page    = $this->client->followRedirect();
        $options = $this->json('/login/mfa/webauthn/options');
        $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form(['webauthn_mfa[credential]' => $member->assert($options)]));
        self::assertResponseRedirects('/login/mfa');
        $failed = $this->eventsOf(WebauthnAuthenticationFailedEvent::class);
        self::assertSame(WebauthnFailure::UNKNOWN_CREDENTIAL, $failed[0]->reason);
        self::assertSame('admin@example.com', $failed[0]->userId, 'The pending user is reported, never the other account.');
        self::assertSame('anonymous|/login/mfa', $this->whoAmI(), 'Still pending.');
        // An empty post (no script) uses no attempt and reports nothing.
        $page = $this->client->request('GET', '/login/mfa');
        $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form());
        self::assertResponseRedirects('/login/mfa');
        self::assertCount(1, $this->eventsOf(WebauthnAuthenticationFailedEvent::class));
    }

    #[Test]
    public function tooManyAttemptsEndTheSignIn(): void
    {
        $this->signIn('admin@example.com', 'admin-password-123');
        $page = $this->client->followRedirect();
        for ($i = 0; $i < 5; ++$i) {
            $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form(['webauthn_mfa[credential]' => '{}']));
            self::assertResponseRedirects('/login/mfa');
        }
        $this->client->submit($page->filter('form[name="webauthn_mfa"]')->form(['webauthn_mfa[credential]' => '{}']));
        self::assertResponseRedirects('/login');
    }

    #[Test]
    public function theSecondStepRoutesNeedAPendingSignIn(): void
    {
        $this->json('/login/mfa/webauthn/options', 401);
        $this->client->request('POST', '/login/mfa/webauthn/assert');
        self::assertResponseRedirects('/login');
        // A signed-in member without a passkey has no passkey step either.
        $this->signedIn('member@example.com', 'member-password-123');
        $this->json('/login/mfa/webauthn/options', 401);
    }

    #[Test]
    public function totpAndPasskeyAreBothOffered(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $this->enrol();
        $this->registerPasskey(new SoftwareAuthenticator());
        $this->client->getCookieJar()->clear();
        $this->signIn('member@example.com', 'member-password-123');
        $page = $this->client->followRedirect();
        self::assertCount(1, $page->filter('form[name="mfa_code_form"]'));
        self::assertCount(1, $page->filter('form[name="webauthn_mfa"]'));
    }
}
