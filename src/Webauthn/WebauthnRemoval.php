<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

/**
 * Outcome of {@see WebauthnManager::remove()} (2.4.0).
 */
enum WebauthnRemoval: string
{
    case Removed = 'removed';

    /** No such passkey of this user (unknown, already removed, or another account's: the same answer). */
    case Unknown = 'unknown';

    /** Refused: it is the user's last second factor while one is required. */
    case LastFactor = 'last_factor';
}
