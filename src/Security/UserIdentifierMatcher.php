<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\Persistence\ObjectRepository;
use Normalizer;
use Nowo\AuthKitBundle\Identity\ExactIdentifierNormalizer;
use Nowo\AuthKitBundle\Identity\UserIdentifierNormalizerInterface;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\NewUser;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use RuntimeException;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function mb_strtolower;
use function sprintf;

/**
 * Looks a user up by an identifier typed by an anonymous client (magic login, password reset, social e-mail match,
 * QR completion) and accepts it only when the STORED identifier equals the input after Unicode NFC normalisation and
 * lower-casing.
 *
 * The database comparison may be accent- and case-insensitive (MySQL utf8mb4_0900_ai_ci, MariaDB *_unicode_ci, ...):
 * without this check `victim@exámple.com` (an IDN domain the attacker owns) resolves to `victim@example.com`, and the
 * magic / reset link of the victim is mailed to the look-alike address (account takeover).
 *
 * 1.49.0: the typed identifier first goes through the {@see UserIdentifierNormalizerInterface} (default: refuse unusable
 * values, change nothing else); the query and the re-check use its result, so a host can move the identifier column to
 * a binary collation. Stateless (worker safe).
 */
final class UserIdentifierMatcher
{
    /** Upper bound of collation-equal rows inspected for one lookup. */
    public const MAX_CANDIDATES = 10;

    private readonly PropertyAccessorInterface $propertyAccessor;

    private readonly UserIdentifierNormalizerInterface $normalizer;

    public function __construct(?PropertyAccessorInterface $propertyAccessor = null, ?UserIdentifierNormalizerInterface $normalizer = null)
    {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
        $this->normalizer       = $normalizer ?? new ExactIdentifierNormalizer();
    }

    /** 1.49.0: the identifier to look up, or null when the configured normaliser refuses it. */
    public function normalizeInput(string $identifier): ?string
    {
        return $identifier === '' ? null : $this->normalizer->normalize($identifier);
    }

    /**
     * 1.49.0 (audit OV): checks the identifier of an account AuthKit is about to create (registration, invitation,
     * admin, social) and returns the {@see NewUser} the factory / host creator receives, its identifier normalised
     * (OV-L1). Refuses (RuntimeException, message prefixed with `$failure`):
     *
     * - an identifier the normaliser refuses (OV-L4): the lookup-port flows could never find that account;
     * - with a normaliser other than the default {@see ExactIdentifierNormalizer}, an identifier whose lower-cased form
     *   is already stored (OV-M1, `UserLookupInterface::hasCaseVariant()`, raw value): on an exact column the new
     *   `bob@x` would otherwise capture every lookup of a legacy `Bob@x`. The default keeps the 1.48.0 byte-exact
     *   behaviour (case variants are distinct accounts where the column compares exactly).
     *
     * A request without identifier (the host derives it from other fields) or with an empty one is left to the host.
     */
    public function prepareNewUser(ProfileSettings $profile, NewUser $newUser, UserLookupInterface $lookup, string $failure): NewUser
    {
        $value = $newUser->identifierValue($profile);
        if ($value === null || $value === '') {
            return $newUser;
        }
        if ($this->normalizer->normalize($value) === null) {
            throw new RuntimeException(sprintf('%s: the identifier is not usable (refused by the identifier normaliser).', $failure));
        }
        if (!$this->normalizer instanceof ExactIdentifierNormalizer && $lookup->hasCaseVariant($profile, $profile->userIdentifierField, $value)) {
            throw new RuntimeException(sprintf('%s: a "%s" with the same identifier (any case) already exists.', $failure, $profile->userClass));
        }

        return $newUser->normalizedWith($profile, $this->normalizer);
    }

    /**
     * 1.49.0: stores the normalised form of the identifier of an account AuthKit creates (registration, invitation,
     * admin, social), when the normaliser changes it. A refused or unreadable value is left as it is. Fallback for a
     * factory that does not store the {@see NewUser} identifier as received ({@see self::prepareNewUser()} normalises
     * it first); a user whose identifier cannot be written keeps the value its factory stored.
     */
    public function normalizeStored(object $user, string $field): void
    {
        $stored = $this->storedIdentifier($user, $field);
        if ($stored === null || !$this->propertyAccessor->isWritable($user, $field)) {
            return;
        }
        $normalized = $this->normalizer->normalize($stored);
        if ($normalized !== null && $normalized !== $stored) {
            // @igor-ignore - Writes the property of the new user object, not of the stateless accessor.
            $this->propertyAccessor->setValue($user, $field, $normalized);
        }
    }

    /**
     * @template T of object
     *
     * @param ObjectRepository<T> $repository
     *
     * @return T|null
     */
    public function find(ObjectRepository $repository, string $field, string $identifier): ?object
    {
        $identifier = $this->normalizeInput($identifier);
        if ($identifier === null) {
            return null;
        }

        foreach ($repository->findBy([$field => $identifier], null, self::MAX_CANDIDATES) as $candidate) {
            if ($this->matches($candidate, $field, $identifier)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Whether the identifier stored on `$user` (property `$field`) equals `$identifier` (NFC, case-insensitive). */
    public function matches(object $user, string $field, string $identifier): bool
    {
        $stored     = $this->storedIdentifier($user, $field);
        $identifier = $this->normalizeInput($identifier);

        return $stored !== null && $identifier !== null && self::normalize($stored) === self::normalize($identifier);
    }

    /**
     * Re-check of a user returned by a host {@see UserLookupInterface} (1.41, JW-A3): the
     * identifier stored on it (property `$field`, else `getUserIdentifier()`) must equal `$identifier` after NFC +
     * lower-casing, whatever the adapter's query did.
     */
    public function matchesUser(UserInterface $user, string $field, string $identifier): bool
    {
        $stored     = $this->storedIdentifier($user, $field) ?? $user->getUserIdentifier();
        $identifier = $this->normalizeInput($identifier);

        return $identifier !== null && self::normalize($stored) === self::normalize($identifier);
    }

    /** The identifier as stored on the user (the address notifiers must use), or null when unreadable. */
    public function storedIdentifier(object $user, string $field): ?string
    {
        if (!$this->propertyAccessor->isReadable($user, $field)) {
            return null;
        }

        $value = $this->propertyAccessor->getValue($user, $field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** NFC + lower case; no trimming (a padded input must not match a stored value). */
    public static function normalize(string $identifier): string
    {
        $normalized = Normalizer::normalize($identifier, Normalizer::FORM_C);

        return mb_strtolower(is_string($normalized) ? $normalized : $identifier, 'UTF-8');
    }
}
