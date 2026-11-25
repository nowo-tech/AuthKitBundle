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
        self::assertNotNull($guard->issuerChangeError($credential, null), 'Removing the issuer is a change too.');
        self::assertNotNull($guard->issuerChangeError((new SocialLoginCredential())->setProvider('gitlab'), 'https://git.example.org'));
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
