<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

/**
 * Why an account left a browser's switcher (1.34, {@see Event\AccountRemovedEvent}).
 */
enum AccountRemovalReason: string
{
    /** The user signed this account out from the switcher. */
    case SignedOut = 'signed_out';

    /** "Sign out of all accounts". */
    case SignedOutAll = 'signed_out_all';

    /** The firewall sign-out (logout route). */
    case Logout = 'logout';

    /** Its session was revoked (UserKit: one session, everywhere, admin, password change, disabled…). */
    case Revoked = 'revoked';

    /** Its session expired and it had no remember-me cookie, or `max_lifetime` passed. */
    case Expired = 'expired';

    /** The user checker or a host listener refused the switch. */
    case Refused = 'refused';

    /** A rotated token was presented after the grace period (cookie theft). */
    case TokenReuse = 'token_reuse';

    /** The user no longer exists. */
    case UserRemoved = 'user_removed';
}
