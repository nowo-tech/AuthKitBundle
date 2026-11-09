<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\Persistence\ObjectRepository;
use Normalizer;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function mb_strtolower;

/**
 * Looks a user up by an identifier typed by an anonymous client (magic login, password reset, social e-mail match,
 * QR completion) and accepts it only when the STORED identifier equals the input after Unicode NFC normalisation and
 * lower-casing.
 *
 * The database comparison may be accent- and case-insensitive (MySQL utf8mb4_0900_ai_ci, MariaDB *_unicode_ci, ...):
 * without this check `victim@exámple.com` (an IDN domain the attacker owns) resolves to `victim@example.com`, and the
 * magic / reset link of the victim is mailed to the look-alike address (account takeover).
 */
final class UserIdentifierMatcher
{
    /** Upper bound of collation-equal rows inspected for one lookup. */
    public const MAX_CANDIDATES = 10;

    private readonly PropertyAccessorInterface $propertyAccessor;

    public function __construct(?PropertyAccessorInterface $propertyAccessor = null)
    {
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
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
        if ($identifier === '') {
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
        $stored = $this->storedIdentifier($user, $field);

        return $stored !== null && self::normalize($stored) === self::normalize($identifier);
    }

    /**
     * Re-check of a user returned by a host {@see UserLookupInterface} (1.41, JW-A3): the
     * identifier stored on it (property `$field`, else `getUserIdentifier()`) must equal `$identifier` after NFC +
     * lower-casing, whatever the adapter's query did.
     */
    public function matchesUser(UserInterface $user, string $field, string $identifier): bool
    {
        $stored = $this->storedIdentifier($user, $field) ?? $user->getUserIdentifier();

        return self::normalize($stored) === self::normalize($identifier);
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
