<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

/**
 * Outcome of checking a second-factor code ({@see MfaManager::check()}).
 */
enum MfaCheck: string
{
    /** A valid, unused TOTP code. */
    case Totp = 'totp';

    /** A valid, unused recovery code (now used). */
    case Recovery = 'recovery';

    /** Wrong, replayed or malformed code. */
    case Invalid = 'invalid';

    /** Too many failures for the account or the client IP: no code is checked. */
    case LockedOut = 'locked_out';

    public function isValid(): bool
    {
        return $this === self::Totp || $this === self::Recovery;
    }
}
