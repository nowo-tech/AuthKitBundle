<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;

use function str_ends_with;
use function str_starts_with;

/**
 * 2.7.1 (owner-reported, Beacon): a remember-me session (only `IS_AUTHENTICATED_REMEMBERED`) opening a page that
 * needs `IS_AUTHENTICATED_FULLY` used to land on the success route: the login page bounced it as a signed-in user. Now
 * the login page asks it to confirm its identity and a successful sign-in returns to the page.
 */
final class RememberedReauthenticationKernelTest extends WebauthnKernelTestCase
{
    #[Test]
    public function thePasskeyPageAsksARememberedSessionToSignInAgainAndComesBack(): void
    {
        $this->remembered();
        $page = $this->client->request('GET', '/account/webauthn');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('[data-testid="auth-kit-webauthn-sign-in-again"]');
        self::assertSelectorNotExists('form[name="webauthn_register"]');
        self::assertSelectorNotExists('[data-nowo-auth-kit-webauthn-signal]');
        $link = $page->filter('[data-testid="auth-kit-webauthn-sign-in-again-link"]')->attr('href');
        self::assertSame('/login?_target_path=/account/webauthn', $link, 'The link signs in again, it does not sign out.');

        $login = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Confirm it's you to continue.", $login->text());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->submit($login->filter('form')->form(['login_form[_username]' => 'member@example.com', 'login_form[_password]' => 'member-password-123']));
        self::assertResponseRedirects();
        self::assertTrue(str_ends_with((string) $this->client->getResponse()->headers->get('Location'), '/account/webauthn'));
        $this->client->followRedirect();
        self::assertSelectorExists('form[name="webauthn_register"]');
    }

    #[Test]
    public function theOptionsAndPostEndpointsStillRefuseARememberedSession(): void
    {
        $this->remembered();
        $this->client->request('GET', '/account/webauthn/register/options');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        foreach (['/account/webauthn/register', '/account/webauthn/' . str_repeat('a', 32) . '/rename', '/account/webauthn/' . str_repeat('a', 32) . '/delete'] as $url) {
            $this->client->request('POST', $url);
            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'), $url);
        }
        self::assertSame(0, $this->em()->getRepository(WebauthnCredential::class)->count([]));
    }

    #[Test]
    public function theMfaPageSendsARememberedSessionToTheLoginFormAndBack(): void
    {
        $this->remembered();
        $this->client->request('GET', '/account/security/mfa');
        self::assertResponseRedirects('http://localhost/login');
        $login = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Confirm it's you to continue.", $login->text());

        $this->client->submit($login->filter('form')->form(['login_form[_username]' => 'member@example.com', 'login_form[_password]' => 'member-password-123']));
        self::assertResponseRedirects('http://localhost/account/security/mfa');
        $this->client->followRedirect();
        self::assertSelectorExists('[data-testid="auth-kit-mfa-secret"]');
    }

    #[Test]
    public function aFullySignedInUserIsStillSentToTheSuccessRoute(): void
    {
        $this->signedIn('member@example.com', 'member-password-123');
        $this->client->request('GET', '/login');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function signingInAsAnotherAccountFollowsTheNormalLoginSemantics(): void
    {
        $this->remembered();
        self::assertStringEndsWith('|' . RememberMeToken::class, $this->whoAmI());
        $this->signedIn('support@example.com', 'support-password-123');
        self::assertTrue(str_starts_with($this->whoAmI(), 'support@example.com|'));
        // The remembered account's cookie is cleared: when the new session expires, the browser never comes back as it.
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->client->getCookieJar()->clear();
        self::assertStringStartsWith('anonymous|', $this->whoAmI());

        // Ticking "Remember me" gives the new account its own cookie.
        $this->remembered();
        $this->signIn('support@example.com', 'support-password-123', true);
        $this->expireSession();
        self::assertStringStartsWith('support@example.com|' . RememberMeToken::class, $this->whoAmI());
    }

    #[Test]
    public function signingInAgainAsTheSameAccountWithRememberMeKeepsTheBrowserRemembered(): void
    {
        $this->remembered();
        $this->signIn('member@example.com', 'member-password-123', true);
        $this->client->followRedirect();
        $this->expireSession();
        self::assertStringStartsWith('member@example.com|' . RememberMeToken::class, $this->whoAmI());
    }

    #[Test]
    public function onlyASameOriginTargetIsKept(): void
    {
        $this->remembered();
        $this->client->request('GET', '/login?_target_path=https%3A%2F%2Fevil.example%2Fsteal');
        self::assertResponseIsSuccessful();
        $this->signIn('member@example.com', 'member-password-123');
        self::assertStringNotContainsString('evil.example', (string) $this->client->getResponse()->headers->get('Location'));

        $this->remembered();
        $this->client->request('GET', '/login?_target_path=%2Fmfa-home');
        self::assertResponseIsSuccessful();
        $this->signIn('member@example.com', 'member-password-123');
        self::assertResponseRedirects('/mfa-home');
    }

    #[Test]
    public function aRememberedSessionConfirmsItsIdentityWithAPasskey(): void
    {
        // Signed in with "Remember me" first: with a passkey, a password sign-in asks for it as a second factor.
        $authenticator = new SoftwareAuthenticator();
        $this->signIn('member@example.com', 'member-password-123', true);
        $this->client->followRedirect();
        $this->registerPasskey($authenticator);
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
        self::assertStringEndsWith('|' . RememberMeToken::class, $this->whoAmI());

        $this->passkeyLogin($authenticator);
        self::assertResponseRedirects();
        self::assertStringStartsWith('member@example.com|', $this->whoAmI());
        self::assertStringEndsNotWith('|' . RememberMeToken::class, $this->whoAmI());
    }

    #[Test]
    public function theReauthenticationFormLetsARememberedSessionResetItsPassword(): void
    {
        // Audit ZDD-L2: "Forgot your password?" no longer bounces; "Create an account" is not offered.
        $this->remembered();
        $login = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $login->selectLink('Create an account'));
        $this->client->click($login->selectLink('Forgot your password?')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="auth-kit-reset_request"]');
    }

    #[Test]
    public function aPasskeySignInAsAnotherAccountClearsTheRememberedCookieAndLandsOnTheTarget(): void
    {
        // Audit ZDD-L3: Security::login() carries no remember-me badge; the subscriber clears the old cookie.
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('support@example.com', 'support-password-123');
        $this->registerPasskey($authenticator);

        $this->remembered();
        $this->client->request('GET', '/account/security/mfa');
        self::assertResponseRedirects('http://localhost/login');
        $this->passkeyLogin($authenticator);
        self::assertResponseRedirects();
        self::assertTrue(str_ends_with((string) $this->client->getResponse()->headers->get('Location'), '/account/security/mfa'));
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'), 'The remembered member never comes back.');
        self::assertStringStartsWith('support@example.com|', $this->whoAmI());
    }

    /**
     * A browser that only has the member's remember-me cookie left (the session expired).
     */
    private function remembered(): void
    {
        $this->client->getCookieJar()->clear();
        $this->signIn('member@example.com', 'member-password-123', true);
        $this->expireSession();
    }

    /**
     * Drops every cookie but the remember-me one (asserting there is one).
     */
    private function expireSession(): void
    {
        $cookie = $this->client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookie);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($cookie);
    }
}
