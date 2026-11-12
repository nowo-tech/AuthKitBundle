<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PasswordReset;

use DateTimeImmutable;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\User\UserIds;

/**
 * One `auth_kit_password_reset` row ({@see PasswordResetRequest}, 1.46.0) as read or written by the repository.
 *
 * @internal
 */
final readonly class PasswordResetRequestRow
{
    /**
     * @param string $userClass the user's class without a Doctrine proxy wrapper
     * @param string|null $userId the user's id as AuthKit stores it ({@see UserIds::of()}); null: referenced by identifier
     * @param string $tokenHash link token hash (link / both) or code hash (code)
     * @param string|null $codeHash code hash of a `both` delivery
     */
    public function __construct(
        public string $profile,
        public string $userClass,
        public ?string $userId,
        public string $userIdentifier,
        public string $tokenHash,
        public ?string $codeHash,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
