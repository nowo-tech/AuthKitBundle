<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Mfa\Security\MfaPendingToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use function parse_url;

use const PHP_URL_PATH;

/**
 * Social and QR sign-in succeed, with session id, remember-me and MFA as they should be. A subclass picks the firewall
 * and the token class its authenticator builds.
 */
abstract class SignInKernelTestCase extends ProgrammaticLoginKernelTestCase
{
    /**
     * @return class-string<TokenInterface>
     */
    abstract protected function expectedToken(): string;

    public function testSocialSignIn(): void
    {
        $sessionBefore = $this->socialCallback();

        self::assertResponseRedirects('/home');
        $token = $this->token();
        self::assertInstanceOf($this->expectedToken(), $token);
        self::assertSame('ada@example.com', $token->getUserIdentifier());
        self::assertSame('social', MfaFirstFactor::of($this->client->getRequest()));
        $this->assertSessionMovedAndNoRememberMe($sessionBefore);
    }

    public function testSocialSignInStillAsksForTheSecondFactor(): void
    {
        $this->enrol();

        $this->socialCallback();

        $token = $this->token();
        self::assertInstanceOf(MfaPendingToken::class, $token);
        self::assertSame('social', $token->firstFactor());
        self::assertInstanceOf($this->expectedToken(), $token->inner());
    }

    public function testQrSignIn(): void
    {
        $sessionBefore = $this->qrCompletion();

        self::assertResponseRedirects('/home');
        $token = $this->token();
        self::assertInstanceOf($this->expectedToken(), $token);
        self::assertSame('ada@example.com', $token->getUserIdentifier());
        self::assertSame('qr', MfaFirstFactor::of($this->client->getRequest()));
        $this->assertSessionMovedAndNoRememberMe($sessionBefore);
    }

    public function testQrSignInStaysExemptFromTheSecondFactorAndSingleUse(): void
    {
        $this->enrol();
        $this->qrCompletion();
        self::assertInstanceOf($this->expectedToken(), $this->token(), 'QR is exempt by default (D-M9).');

        $location = (string) parse_url($this->client->getRequest()->getUri(), PHP_URL_PATH);
        $this->client->request('GET', '/logout');
        $this->client->request('GET', $location);
        self::assertResponseRedirects();
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'), 'A consumed challenge does not sign in again.');
    }
}
