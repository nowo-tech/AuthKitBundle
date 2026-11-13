<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Security\ExactIdentifier;
use ReflectionClass;

use function mb_strlen;
use function sprintf;

/**
 * Stable reference of a user for MFA (1.34): the Doctrine root entity class and identifier, so an e-mail / username
 * change can never detach a second factor ({@see MfaOwnerResolver}).
 *
 * Both parts are database keys matched byte for byte (REQ-SEC-006, 1.46.1): edge whitespace or NBSP, control or format
 * characters (ZWSP, BOM, bidi marks), invalid UTF-8 and over-long values are refused ({@see ExactIdentifier}), so a
 * look-alike of another user's id can never be an owner.
 */
final readonly class MfaOwner
{
    public const MAX_LENGTH = 191;

    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (!self::usable($type) || !self::usable($id)) {
            throw new InvalidArgumentException(sprintf('An MFA owner needs a non-empty type and id of at most %d characters, without edge white space, control or format characters, in valid UTF-8.', self::MAX_LENGTH));
        }
    }

    /**
     * An owner under a reference stored before 1.46.1 that {@see self::usable()} now refuses (edge white space, control or
     * format characters), for personal-data erasure only: its rows are still found byte for byte and deleted, never
     * created or used to sign in. Empty or over-long references are still refused.
     *
     * @internal
     */
    public static function legacy(string $type, string $id): self
    {
        if ($type === '' || $id === '' || mb_strlen($type, 'UTF-8') > self::MAX_LENGTH || mb_strlen($id, 'UTF-8') > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('An MFA owner needs a non-empty type and id of at most %d characters.', self::MAX_LENGTH));
        }
        $owner = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        // @igor-ignore - A new value object being initialised (readonly properties, class scope), not shared state.
        $owner->type = $type; // @phpstan-ignore property.readOnlyAssignNotInConstructor (first assignment, class scope)
        // @igor-ignore - A new value object being initialised (readonly properties, class scope), not shared state.
        $owner->id = $id; // @phpstan-ignore property.readOnlyAssignNotInConstructor (first assignment, class scope)

        return $owner;
    }

    /** Whether `$value` can be the type or the id of an owner. */
    public static function usable(string $value): bool
    {
        return ExactIdentifier::usable($value, self::MAX_LENGTH);
    }

    public function key(): string
    {
        return $this->type . '#' . $this->id;
    }
}
