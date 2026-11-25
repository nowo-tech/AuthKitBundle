<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Support\Webauthn\SoftwareAuthenticator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Audit ZBX-L1: with `webauthn.mfa: false` a passkey is not a second factor, so the default exemption of the
 * `webauthn` first factor does not apply: a passkey sign-in of a user with TOTP still asks for the code.
 */
final class WebauthnNotMfaKernelTest extends WebauthnKernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WebauthnNotMfaTestKernel::class;
    }

    #[Test]
    public function aPasskeySignInStillAsksForTheTotpCode(): void
    {
        $authenticator = new SoftwareAuthenticator();
        $this->signedIn('member@example.com', 'member-password-123');
        $this->enrol();
        $this->registerPasskey($authenticator);
        $this->client->getCookieJar()->clear();

        $this->passkeyLogin($authenticator);
        self::assertResponseRedirects('/login/mfa');
        self::assertSame('anonymous|/login/mfa', $this->whoAmI(), 'Pending until the code is entered.');
        $page = $this->client->request('GET', '/login/mfa');
        self::assertCount(0, $page->filter('form[name="webauthn_mfa"]'), 'A passkey is not offered as the second step.');
        self::assertCount(1, $page->filter('form[name="mfa_code_form"]'));
    }
}
