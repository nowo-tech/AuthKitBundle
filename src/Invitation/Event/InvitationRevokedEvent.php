<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Event;

/**
 * Dispatched after an invitation was revoked (its link stopped working).
 */
final class InvitationRevokedEvent extends InvitationEvent
{
}
