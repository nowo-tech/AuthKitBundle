<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use RuntimeException;

/**
 * Thrown by an {@see InvitationGrantInterface} to refuse an acceptance (the transaction is rolled back). The message
 * is never shown to the invitee.
 */
final class InvitationRefusedException extends RuntimeException
{
}
