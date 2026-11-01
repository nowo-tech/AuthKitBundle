<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched after an invitation was accepted and its account committed (before the sign-in). `actor` and `user` are
 * the new account.
 */
final class InvitationAcceptedEvent extends InvitationEvent
{
    public function __construct(
        InvitationSnapshot $invitation,
        public readonly UserInterface $user,
        DateTimeImmutable $occurredAt,
    ) {
        parent::__construct($invitation, $user, $occurredAt);
    }
}
