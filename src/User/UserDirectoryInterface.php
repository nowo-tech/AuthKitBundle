<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Port (1.46.0): the framework-free twin of the deprecated {@see UserLookupInterface} (REQ-ARCH-001 rule 3): an
 * {@see AuthUserRef} instead of the Symfony `UserInterface`. Alias this interface to your adapter (instead of the
 * deprecated one); AuthKit then asks it for every lookup the deprecated port served.
 *
 * Every reference you return must carry your security user object ({@see AuthUserRef::of()}): AuthKit signs it in or
 * hands it to Symfony Security. A reference by id alone is refused with a `LogicException`.
 */
interface UserDirectoryInterface
{
    /**
     * The user whose STORED identifier equals `$identifier` after Unicode NFC normalisation and lower-casing, never a
     * collation look-alike (SEC-004 H1, see {@see UserIdentifierMatcher}); null when no user or MORE THAN ONE user
     * matches. Must return fresh state (long-lived workers: bans and identifier changes apply).
     * 1.49.0: AuthKit passes `$identifier` normalised by
     * {@see UserIdentifierNormalizerInterface}.
     */
    public function findByIdentifier(ProfileSettings $profile, string $identifier): ?AuthUserRef;

    /** The user with this id (as stored by AuthKit: `(string) getId()`), fresh. */
    public function findById(ProfileSettings $profile, string $id): ?AuthUserRef;

    /** Number of users of the profile (`first_user_only`). */
    public function countUsers(ProfileSettings $profile): int;

    /**
     * Whether a user already stores `$value` in `$property` in any letter case. Return false when your store enforces
     * case-insensitive uniqueness itself.
     * 1.49.0: also asked by every creation path (registration, admin, social) when the identifier normaliser is not the
     * default one. `$value` is the RAW identifier (not normalised): compare it case-insensitively.
     */
    public function hasCaseVariant(ProfileSettings $profile, string $property, string $value): bool;
}
