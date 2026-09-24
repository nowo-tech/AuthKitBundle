<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a QR login challenge is no longer in the expected state in the database
 * (another request or worker already approved, denied, consumed, or expired it).
 */
final class QrLoginChallengeConflictException extends RuntimeException
{
    public static function forTransition(string $challengeId, QrLoginChallengeStatus $from, QrLoginChallengeStatus $to): self
    {
        return new self(sprintf(
            'QR login challenge "%s" could not move from "%s" to "%s": it was already changed by another request.',
            $challengeId,
            $from->value,
            $to->value,
        ));
    }
}
