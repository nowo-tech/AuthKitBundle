<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use RuntimeException;

/**
 * 2.2.0 (REQ-RUNTIME-003): thrown by {@see PasswordResetCompleter::complete()} when the reset credential was used,
 * cleared or replaced by another request between the check of the link (or code) and the claim. Nothing was written:
 * the password is unchanged. The reset pages answer it like an invalid link or code.
 */
final class PasswordResetLinkUsedException extends RuntimeException
{
    public function __construct(string $message = 'The password reset credential was already used.')
    {
        parent::__construct($message);
    }
}
