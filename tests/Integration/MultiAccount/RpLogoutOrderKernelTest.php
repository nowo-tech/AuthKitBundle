<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\EventSubscriber\RpInitiatedLogoutSubscriber;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\EventListener\DefaultLogoutListener;
use Symfony\Component\Security\Http\EventListener\SessionLogoutListener;

use function is_array;
use function is_object;

/**
 * 2.8.0 (audit ZDC-I1): on a multi-account firewall the RP-initiated logout runs after the default target and the
 * multi-account sign-out (whose chooser redirect it may replace) and before the session is invalidated.
 */
final class RpLogoutOrderKernelTest extends MultiAccountKernelTestCase
{
    public function testTheLogoutListenersRunInAFixedOrder(): void
    {
        $dispatcher = self::getContainer()->get('security.event_dispatcher.main');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $order = [];
        foreach ($dispatcher->getListeners(LogoutEvent::class) as $listener) {
            $object = is_array($listener) ? $listener[0] : $listener;
            $object = is_object($object) ? $object::class : '';
            foreach ([DefaultLogoutListener::class, MultiAccountSubscriber::class, RpInitiatedLogoutSubscriber::class, SessionLogoutListener::class] as $class) {
                if ($object === $class) {
                    $order[] = $class;
                }
            }
        }

        self::assertSame([DefaultLogoutListener::class, MultiAccountSubscriber::class, RpInitiatedLogoutSubscriber::class, SessionLogoutListener::class], $order);
    }
}
