<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MagicLogin;

use RuntimeException;

/** Thrown by {@see AdminMagicLinkIssuer} when the link may not be issued; the message is one of the reason codes. */
final class AdminMagicLinkRefusedException extends RuntimeException
{
    public const OWN_ACCOUNT    = 'own_account';
    public const PROTECTED_ROLE = 'protected_role';
    public const NOT_CONFIGURED = 'not_configured';

    /** One of the constants above. */
    public function reason(): string
    {
        return $this->getMessage();
    }
}
