<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

/**
 * Creates, resolves, and clears password reset credentials on the user entity.
 */
interface PasswordResetTokenManagerInterface
{
    public function createForUser(object $user): PasswordResetTokenResult;

    public function resolveUserByLinkToken(string $linkToken): ?object;

    public function resolveUserByIdentifierAndCode(string $identifier, string $code): ?object;

    public function clearForUser(object $user): void;
}
