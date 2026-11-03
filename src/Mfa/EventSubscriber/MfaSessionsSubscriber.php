<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\EventSubscriber;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsDeletedEvent;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * UserKit sessions → MFA (1.34, registered only with UserKit installed): signing a user out everywhere (own action,
 * administrator, account disabled, password changed) forgets the user's trusted devices; deleting every session of a
 * removed user (`forgetUser()`, personal-data eraser) deletes the user's second factor.
 */
final readonly class MfaSessionsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MfaManager $manager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserSessionsRevokedEvent::class => 'onRevoked',
            UserSessionsDeletedEvent::class => 'onDeleted',
        ];
    }

    public function onRevoked(UserSessionsRevokedEvent $event): void
    {
        if ($event->user instanceof UserInterface && MfaManager::forgetsDevices($event->reason->value) && $this->manager->profileFor($event->user) instanceof ProfileSettings) {
            $this->manager->forgetDevices($event->user, 'sessions');
        }
    }

    public function onDeleted(UserSessionsDeletedEvent $event): void
    {
        if ($event->user instanceof UserInterface && $this->manager->profileFor($event->user) instanceof ProfileSettings) {
            $this->manager->forgetUser($event->user);
        }
    }
}
