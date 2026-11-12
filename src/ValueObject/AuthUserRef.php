<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Internal\HiddenValues;

use function in_array;
use function is_object;
use function is_string;
use function trim;

/**
 * Framework-free reference to a host user (1.46.0, REQ-ARCH-001 rule 3): what the 1.46 ports and the event accessors
 * give and take instead of the Symfony `UserInterface`.
 *
 * Its properties hold the user class (without a Doctrine proxy wrapper), the user id (the string form of `getId()`, as
 * AuthKit stores it; null for a user without a usable id), the user identifier and the role names the user object
 * reports (no role hierarchy). The user object itself ({@see user()}) is **not** a property: it lives in an internal
 * {@see \WeakMap} keyed by this object, so a dump, `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of
 * the VO never shows a password hash or another field of the user, and a `clone` or an unserialized copy has no user
 * object.
 *
 * Where AuthKit must sign a user in, hash a password or hand a user to Symfony Security (the user ports), the reference
 * must carry the security user object your user provider returns ({@see self::of()}): a reference by id alone
 * ({@see self::ofId()}) is refused there with a clear error. Elsewhere (events, policies) the user object is optional.
 *
 * Built per call and never stored by the bundle (FrankenPHP worker mode, kernel reset false).
 */
final readonly class AuthUserRef
{
    private const USER = 'user';

    /** @var list<string> */
    public array $roles;

    /**
     * @param string $userClass the user class ('' when unknown)
     * @param string|null $id the user id (null: the user has no usable id); an empty or blank id is refused
     * @param string $identifier the user identifier (login), '' when unknown
     * @param array<array-key, mixed> $roles the role names the user reports (no hierarchy; non-string values are dropped)
     * @param object|null $user the host's user object (hidden, see the class comment)
     *
     * @throws InvalidArgumentException when the id is empty or blank
     */
    public function __construct(
        public string $userClass,
        public ?string $id,
        public string $identifier = '',
        array $roles = [],
        ?object $user = null,
    ) {
        if ($id !== null && trim($id) === '') {
            throw new InvalidArgumentException('An AuthKit user id cannot be empty; pass null for a user without an id.');
        }

        $kept = [];
        foreach ($roles as $role) {
            if (is_string($role)) {
                $kept[] = $role;
            }
        }
        $this->roles = $kept;
        HiddenValues::put($this, [self::USER => $user]);
    }

    /**
     * A reference that carries the host's (security) user object, e.g. from your user adapter.
     *
     * @param array<array-key, mixed> $roles
     */
    public static function of(object $user, string $userClass, ?string $id, string $identifier, array $roles = []): self
    {
        return new self($userClass, $id, $identifier, $roles, $user);
    }

    /**
     * A reference known only by its id (no user object): e.g. from a message payload.
     *
     * @param array<array-key, mixed> $roles
     *
     * @throws InvalidArgumentException when the id is empty or blank
     */
    public static function ofId(string $userClass, string $id, string $identifier = '', array $roles = []): self
    {
        return new self($userClass, $id, $identifier, $roles);
    }

    /**
     * The host's user object, or null (a reference built from an id, a clone or an unserialized copy).
     */
    public function user(): ?object
    {
        $user = HiddenValues::get($this, self::USER);

        return is_object($user) ? $user : null;
    }

    /** Whether the reference has a user id. */
    public function hasId(): bool
    {
        return $this->id !== null;
    }

    /** Whether the user reports this role name (no role hierarchy). */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Whether both references name the same user: same class and same id, byte for byte (false when either has no id).
     */
    public function isSameUserAs(self $other): bool
    {
        return $this->id !== null && $this->id === $other->id && $this->userClass === $other->userClass;
    }
}
