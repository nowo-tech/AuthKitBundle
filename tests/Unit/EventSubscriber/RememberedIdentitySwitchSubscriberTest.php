<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Closure;
use Nowo\AuthKitBundle\EventSubscriber\RememberedIdentitySwitchSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

final class RememberedIdentitySwitchSubscriberTest extends TestCase
{
    public function testListensBeforeTheRememberMeListener(): void
    {
        self::assertSame([LoginSuccessEvent::class => ['onLoginSuccess', 0]], RememberedIdentitySwitchSubscriber::getSubscribedEvents());
    }

    public function testAnotherAccountClearsTheRememberedCookie(): void
    {
        $handler = $this->createMock(RememberMeHandlerInterface::class);
        $handler->expects(self::once())->method('clearRememberMeCookie');

        $this->subscriber(['main' => $handler])->onLoginSuccess($this->event('bob', new RememberMeToken(new InMemoryUser('ada', null), 'main')));
    }

    public function testTheSameAccountOrAFullSessionKeepsTheCookie(): void
    {
        $handler = $this->createMock(RememberMeHandlerInterface::class);
        $handler->expects(self::never())->method('clearRememberMeCookie');
        $subscriber = $this->subscriber(['main' => $handler]);

        $subscriber->onLoginSuccess($this->event('ada', new RememberMeToken(new InMemoryUser('ada', null), 'main')));
        $subscriber->onLoginSuccess($this->event('bob', new UsernamePasswordToken(new InMemoryUser('ada', null), 'main')));
        $subscriber->onLoginSuccess($this->event('bob', null));
    }

    public function testAFirewallWithoutRememberMeIsLeftAlone(): void
    {
        $handler = $this->createMock(RememberMeHandlerInterface::class);
        $handler->expects(self::never())->method('clearRememberMeCookie');

        $this->subscriber(['other' => $handler])->onLoginSuccess($this->event('bob', new RememberMeToken(new InMemoryUser('ada', null), 'main')));
        $this->subscriber(['main' => $this->createMock(LoginLinkHandlerInterface::class)])->onLoginSuccess($this->event('bob', new RememberMeToken(new InMemoryUser('ada', null), 'main')));
    }

    /**
     * @param array<string, object> $handlers
     */
    private function subscriber(array $handlers): RememberedIdentitySwitchSubscriber
    {
        return new RememberedIdentitySwitchSubscriber(new ServiceLocator(array_map(static fn (object $handler): Closure => static fn (): object => $handler, $handlers)));
    }

    private function event(string $identifier, ?TokenInterface $previous): LoginSuccessEvent
    {
        $user = new InMemoryUser($identifier, null);

        return new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($identifier, static fn (): InMemoryUser => $user)),
            new UsernamePasswordToken($user, 'main'),
            new Request(),
            null,
            'main',
            $previous,
        );
    }
}
