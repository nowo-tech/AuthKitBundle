<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MagicLogin;

use Nowo\AuthKitBundle\MagicLogin\MagicLoginDisabledListener;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\LoginLinkAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

final class MagicLoginDisabledListenerTest extends TestCase
{
    public function testAHostLoginLinkRouteWithoutProfileIsLeftAlone(): void
    {
        $this->listener('disabled', new Request())->onCheckPassport($this->event($this->loginLinkAuthenticator()));
        $this->addToAssertionCount(1);
    }

    public function testOtherAuthenticatorsAreLeftAlone(): void
    {
        $this->listener('disabled', $this->authKitRequest())->onCheckPassport($this->event($this->createStub(AuthenticatorInterface::class)));
        $this->addToAssertionCount(1);
    }

    public function testEnabledModeIsLeftAlone(): void
    {
        $this->listener('enabled', $this->authKitRequest())->onCheckPassport($this->event($this->loginLinkAuthenticator()));
        $this->addToAssertionCount(1);
    }

    public function testDisabledModeRefusesAuthKitRoutes(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->listener('disabled', $this->authKitRequest())->onCheckPassport($this->event($this->loginLinkAuthenticator()));
    }

    private function loginLinkAuthenticator(): LoginLinkAuthenticator
    {
        return new LoginLinkAuthenticator(
            $this->createStub(LoginLinkHandlerInterface::class),
            new HttpUtils(),
            $this->createStub(AuthenticationSuccessHandlerInterface::class),
            $this->createStub(AuthenticationFailureHandlerInterface::class),
            [],
        );
    }

    private function authKitRequest(): Request
    {
        $request = new Request();
        $request->attributes->set(RequestProfileResolver::REQUEST_ATTRIBUTE, 'default');

        return $request;
    }

    private function listener(string $mode, Request $request): MagicLoginDisabledListener
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new MagicLoginDisabledListener($stack, new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, ['magic_login' => ['mode' => $mode]])));
    }

    private function event(AuthenticatorInterface $authenticator): CheckPassportEvent
    {
        return new CheckPassportEvent($authenticator, new SelfValidatingPassport(new UserBadge('someone')));
    }
}
