<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Base of the audit events dispatched after every invitation mutation (created, resent, revoked, accepted). The
 * invitation is a {@see InvitationSnapshot}: neither the token, its hash nor the link is part of an event.
 */
abstract class InvitationEvent
{
    /**
     * @param UserInterface|null $actor who did it (null: a command, or an anonymous demo administrator); @deprecated since 1.46.0, use actorRef(); removed in 2.0
     */
    public function __construct(
        public readonly InvitationSnapshot $invitation,
        public readonly ?UserInterface $actor,
        public readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Who did it, framework-free (1.46.0); null: a command, or an anonymous demo administrator.
     */
    public function actorRef(): ?AuthUserRef
    {
        return AuthUserRefs::fromNullableUser($this->actor);
    }
}
