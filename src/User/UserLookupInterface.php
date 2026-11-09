<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Port (1.41): finds the users of a profile for password reset, magic login, QR completion, MFA admin, social sign-in,
 * the registration gate and the registrar. Default: {@see Doctrine\DoctrineUserLookup} (repository of `user_class`).
 * Alias the interface to an adapter over your user repository or your user provider.
 */
interface UserLookupInterface
{
    /**
     * The user whose STORED identifier equals `$identifier` after Unicode NFC normalisation and lower-casing, never a
     * collation look-alike (SEC-004 H1, see {@see UserIdentifierMatcher}). Must return
     * fresh state (long-lived workers: bans and identifier changes apply).
     */
    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?UserInterface;

    /** The user with this id (as stored by AuthKit: `(string) getId()`), fresh. */
    public function findById(ProfileSettings $profile, string $id): ?UserInterface;

    /** Number of users of the profile (`first_user_only`). */
    public function countUsers(ProfileSettings $profile): int;

    /**
     * Whether a user already stores `$value` in `$property` in any letter case (SEC-004 CD L2: invited registration
     * on case-sensitive databases). Return false when your store enforces case-insensitive uniqueness itself.
     */
    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool;
}
