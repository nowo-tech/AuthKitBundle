<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\EventSubscriber;

use Nowo\AuthKitBundle\DeviceIntelligence\DeviceIntelligenceContext;
use Nowo\AuthKitBundle\DeviceIntelligence\NewDeviceLoginNotificationContext;
use Nowo\AuthKitBundle\DeviceIntelligence\NewDeviceLoginNotifierInterface;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * After password/SSO/magic login: optional session flag + notifier when the device cluster is new.
 *
 * Does not grant DeviceTrust. Device Intelligence still associates the user on LoginSuccessEvent.
 */
final class NewDeviceLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestProfileResolver $profileResolver,
        private readonly NewDeviceLoginNotifierInterface $notifier,
        private readonly DeviceIntelligenceContext $devices = new DeviceIntelligenceContext(),
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $config  = $this->profileResolver->resolve($request)->deviceIntelligence;
        if (!$this->devices->shouldNotifyNewDevice($config) || !$this->devices->isNew($request)) {
            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->set(DeviceIntelligenceContext::SESSION_NEW_DEVICE, true);
        }

        $this->notifier->notify(new NewDeviceLoginNotificationContext(
            $event->getUser()->getUserIdentifier(),
            $this->profileResolver->resolve($request)->name,
        ));
    }
}
