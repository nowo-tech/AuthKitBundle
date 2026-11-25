<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialIssuerGuard;
use PHPUnit\Framework\TestCase;

final class SocialCredentialIssuerGuardTest extends TestCase
{
    public function testTheIssuerIsLockedWhileAccountsAreLinked(): void
    {
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('count')->with(['provider' => 'gitlab'])->willReturn(3);
        $guard      = new SocialCredentialIssuerGuard($accounts);
        $credential = (new SocialLoginCredential())->setProvider('gitlab')->setIssuer('https://gitlab.com');

        self::assertNull($guard->issuerChangeError($credential, 'https://gitlab.com/'), 'Same issuer (trailing slash) is no change.');
        self::assertNull($guard->issuerChangeError($credential, ' https://gitlab.com '));
        self::assertStringContainsString('"gitlab"', (string) $guard->issuerChangeError($credential, 'https://git.example.org'));
        self::assertNull($guard->issuerChangeError($credential, null), 'ZBP-v-I2: no issuer on the gitlab preset is gitlab.com.');
        self::assertNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider('gitlab'), 'https://gitlab.com'));
        self::assertNotNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider('gitlab'), 'https://git.example.org'));
    }

    public function testEffectiveIssuersAreCompared(): void
    {
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('count')->willReturn(1);
        $guard = new SocialCredentialIssuerGuard($accounts);

        // A generic provider: removing the issuer turns it into OAuth2, a change.
        self::assertNotNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider('okta')->setIssuer('https://a.example.com'), null));
        foreach (['apple' => 'https://appleid.apple.com', 'linkedin' => 'https://www.linkedin.com/oauth', 'slack' => 'https://slack.com'] as $provider => $issuer) {
            self::assertNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider($provider), $issuer . '/'), $provider);
        }
        // A preset key on custom OAuth2 endpoints has no issuer: opting it into OIDC is a change.
        $oauth2 = (new SocialLoginCredential())->setProvider('gitlab')->setAuthorizeUrl('https://git.example.org/oauth/authorize');
        self::assertNotNull($guard->issuerChangeError($oauth2, 'https://gitlab.com'));
        foreach (['setTokenUrl', 'setUserinfoUrl'] as $setter) {
            self::assertNotNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider('GitLab')->{$setter}('https://git.example.org/x'), 'https://gitlab.com'));
        }
    }

    public function testWithoutLinkedAccountsTheIssuerMayChange(): void
    {
        $accounts = $this->createMock(SocialLoginAccountRepository::class);
        $accounts->method('count')->willReturn(0);
        $guard = new SocialCredentialIssuerGuard($accounts);

        self::assertNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider('okta')->setIssuer('https://a.example.com'), 'https://b.example.com'));
        self::assertNull($guard->issuerChangeError(new SocialLoginCredential(), 'https://b.example.com'), 'A credential not saved yet has no provider key.');
        self::assertNull($guard->issuerChangeError(new SocialLoginCredential(), ''));
    }
}
