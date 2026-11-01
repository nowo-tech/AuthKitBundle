<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched after an invitation was sent again with a new link (also when inviting an e-mail that already has an open invitation). `delivery` says how the
 * link left: `email`, `link` (shown once to the inviter) or `none` (not delivered).
 */
final class InvitationResentEvent extends InvitationEvent
{
    public const DELIVERY_EMAIL = 'email';

    public const DELIVERY_LINK = 'link';

    public const DELIVERY_NONE = 'none';

    public function __construct(
        InvitationSnapshot $invitation,
        ?UserInterface $actor,
        DateTimeImmutable $occurredAt,
        public readonly string $delivery,
    ) {
        parent::__construct($invitation, $actor, $occurredAt);
    }
}
