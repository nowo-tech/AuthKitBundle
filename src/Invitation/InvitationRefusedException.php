<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

use RuntimeException;

/**
 * Thrown by an {@see InvitationGrantInterface} to refuse an acceptance (the transaction is rolled back). The message
 * is never shown to the invitee; the optional {@see InvitationRefusal} (1.33, {@see self::because()}) is.
 */
final class InvitationRefusedException extends RuntimeException
{
    public ?InvitationRefusal $reason = null;

    /** A refusal whose translatable reason is shown to the invitee (e.g. "no seat left"). */
    public static function because(InvitationRefusal $reason, string $message = 'Invitation refused by the host.'): self
    {
        $exception         = new self($message);
        $exception->reason = $reason;

        return $exception;
    }
}
