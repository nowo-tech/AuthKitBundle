<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Stub;

use LogicException;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetCredentialClaimInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;

/**
 * 2.2.0: a reset credential store with the single-use claim, answering `$claims` and resolving `$user` for any link
 * or code. Records the users it claimed or cleared.
 */
final class ClaimingResetStore implements PasswordResetTokenManagerInterface, PasswordResetCredentialClaimInterface
{
    /** @var list<object> */
    public array $claimed = [];

    /** @var list<object> */
    public array $cleared = [];

    public function __construct(
        private readonly bool $claims,
        private readonly ?object $user = null,
    ) {
    }

    public function createForUser(object $user): PasswordResetTokenResult
    {
        throw new LogicException('Not used.');
    }

    public function resolveUserByLinkToken(string $linkToken, ?string $profileName = null): ?object
    {
        return $this->user;
    }

    public function resolveUserByIdentifierAndCode(string $identifier, string $code, ?string $profileName = null): ?object
    {
        return $this->user;
    }

    public function clearForUser(object $user): void
    {
        $this->cleared[] = $user;
    }

    public function claimForUser(object $user): bool
    {
        $this->claimed[] = $user;

        return $this->claims;
    }
}
