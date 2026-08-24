<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\DeviceIntelligence\NewDeviceLoginNotificationContext;
use Nowo\AuthKitBundle\DeviceIntelligence\NewDeviceLoginNotifierInterface;
use Nowo\AuthKitBundle\DeviceIntelligence\NullNewDeviceLoginNotifier;
use Nowo\AuthKitBundle\EventSubscriber\NewDeviceLoginSubscriber;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class NewDeviceLoginSubscriberTest extends TestCase
{
    public function testSubscribesToLoginSuccess(): void
    {
        self::assertSame(
            [LoginSuccessEvent::class => 'onLoginSuccess'],
            NewDeviceLoginSubscriber::getSubscribedEvents(),
        );
    }

    public function testSkipsWhenNotifyDisabled(): void
    {
        $notifier = $this->createMock(NewDeviceLoginNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');

        $subscriber = new NewDeviceLoginSubscriber(
            ProfileRegistryFactory::requestResolver(TestUser::class),
            $notifier,
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $subscriber->onLoginSuccess($this->event(Request::create('/login'), new TestUser('a@b.c')));
    }

    public function testNotifiesAndSetsSessionWhenDeviceIsNew(): void
    {
        $notifier = $this->createMock(NewDeviceLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify')->with(self::callback(
            static function (NewDeviceLoginNotificationContext $context): bool {
                return $context->userIdentifier === 'a@b.c' && $context->profileName === 'default';
            },
        ));

        $subscriber = new NewDeviceLoginSubscriber(
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => true,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => false],
                ],
            ]),
            $notifier,
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $request = Request::create('/login');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isNew(): bool
            {
                return true;
            }
        });

        $user = new TestUser('a@b.c');
        $subscriber->onLoginSuccess($this->event($request, $user));

        self::assertTrue($session->get(DeviceIntelligenceContext::SESSION_NEW_DEVICE));
    }

    public function testSkipsWhenDeviceIsNotNew(): void
    {
        $notifier = $this->createMock(NewDeviceLoginNotifierInterface::class);
        $notifier->expects(self::never())->method('notify');

        $subscriber = new NewDeviceLoginSubscriber(
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => true,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => false],
                ],
            ]),
            $notifier,
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $request = Request::create('/login');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isNew(): bool
            {
                return false;
            }
        });

        $subscriber->onLoginSuccess($this->event($request, new TestUser('a@b.c')));
    }

    public function testNotifiesWithoutSession(): void
    {
        $notifier = $this->createMock(NewDeviceLoginNotifierInterface::class);
        $notifier->expects(self::once())->method('notify');

        $subscriber = new NewDeviceLoginSubscriber(
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'device_intelligence' => [
                    'enabled'               => true,
                    'collect_on_auth_pages' => true,
                    'collect_endpoint'      => '/_device/collect',
                    'new_device_notify'     => true,
                    'device_rate_limit'     => false,
                    'qr_login'              => ['approve_require_trusted' => false],
                ],
            ]),
            $notifier,
            new DeviceIntelligenceContext(static fn (string $class): bool => true),
        );

        $request = Request::create('/login');
        $request->attributes->set(DeviceIntelligenceContext::REQUEST_ATTRIBUTE, new class {
            public function isNew(): bool
            {
                return true;
            }
        });

        $subscriber->onLoginSuccess($this->event($request, new TestUser('a@b.c')));
    }

    public function testNullNotifierIsNoOp(): void
    {
        (new NullNewDeviceLoginNotifier())->notify(new NewDeviceLoginNotificationContext('a@b.c', 'default'));

        $this->expectNotToPerformAssertions();
    }

    private function event(Request $request, UserInterface $user): LoginSuccessEvent
    {
        $event = $this->createMock(LoginSuccessEvent::class);
        $event->method('getRequest')->willReturn($request);
        $event->method('getUser')->willReturn($user);

        return $event;
    }
}
