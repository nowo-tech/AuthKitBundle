<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use RuntimeException;

/**
 * MFA cannot be set up for this user (1.47.0, REQ-SEC-006): the MFA table still has a folding unique key and another
 * owner's row matches this owner (a look-alike id such as `Jose@` next to `jose@`). Thrown before any write, so the
 * entity manager stays open; never resolved by falling back to the other owner's row. Apply the ALTERs of UPGRADING.
 */
final class MfaUnavailableException extends RuntimeException
{
}
