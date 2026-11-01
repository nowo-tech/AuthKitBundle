<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use LogicException;
use Nowo\AuthKitBundle\Invitation\Event\InvitationAcceptedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationCreatedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationResentEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationRevokedEvent;
use Nowo\AuthKitBundle\Invitation\Event\InvitationsPurgedEvent;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRefusedException;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Records the invitation audit events and the grant calls; `refuse` makes the grant refuse the acceptance.
 */
final class CapturingInvitationListener implements EventSubscriberInterface, InvitationGrantInterface
{
    /** @var list<object> */
    public array $events = [];

    /** @var list<string> */
    public array $granted = [];

    /** @var list<InvitationsPurgedEvent> */
    public array $purged = [];

    public bool $refuse = false;

    public bool $crash = false;

    public static function getSubscribedEvents(): array
    {
        return [
            InvitationCreatedEvent::class  => 'record',
            InvitationResentEvent::class   => 'record',
            InvitationRevokedEvent::class  => 'record',
            InvitationAcceptedEvent::class => 'record',
            InvitationsPurgedEvent::class  => 'purged',
        ];
    }

    public function record(object $event): void
    {
        $this->events[] = $event;
    }

    public function purged(InvitationsPurgedEvent $event): void
    {
        $this->purged[] = $event;
    }

    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
        if ($this->crash) {
            throw new LogicException('Grant bug.');
        }
        if ($this->refuse) {
            throw new InvitationRefusedException('No seat left.');
        }
        $this->granted[] = $invitation->email . ' -> ' . $user->getUserIdentifier();
    }
}
