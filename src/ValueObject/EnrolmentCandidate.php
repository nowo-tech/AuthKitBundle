<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\ValueObject;

use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;

use function is_scalar;
use function is_string;

/**
 * Framework-free description of a sign-in that may join the account switcher (1.46.0, REQ-ARCH-001 rule 3), instead
 * of the Symfony security token, for {@see EnrolmentPolicyInterface}.
 */
final readonly class EnrolmentCandidate
{
    /** @var list<string> */
    public array $roleNames;

    /** @var array<string, scalar|null> */
    public array $attributes;

    /**
     * @param AuthUserRef|null $user the signed-in user (null: the token has no user object)
     * @param string|null $firewall the token's firewall, when it reports one
     * @param bool $secondFactorPending AuthKit's native second factor is still to be passed (MFA pending token)
     * @param bool $impersonated the token is an impersonation (switch user)
     * @param array<array-key, mixed> $roleNames the token's role names (non-strings dropped)
     * @param array<array-key, mixed> $attributes the token attributes with a string name and a scalar or null value
     * @param bool $rememberMe the sign-in comes from a remember-me cookie (Symfony `RememberMeToken`)
     * @param string $tokenClass the token class, e.g. to recognise another bundle's "second factor pending" token,
     *                           which {@see self::$secondFactorPending} does not detect (it only knows AuthKit's)
     */
    public function __construct(
        public ?AuthUserRef $user,
        public ?string $firewall = null,
        public bool $secondFactorPending = false,
        public bool $impersonated = false,
        array $roleNames = [],
        array $attributes = [],
        public bool $rememberMe = false,
        public string $tokenClass = '',
    ) {
        $roles = [];
        foreach ($roleNames as $role) {
            if (is_string($role)) {
                $roles[] = $role;
            }
        }
        $this->roleNames = $roles;

        $kept = [];
        foreach ($attributes as $name => $value) {
            if (is_string($name) && ($value === null || is_scalar($value))) {
                $kept[$name] = $value;
            }
        }
        $this->attributes = $kept;
    }

    /**
     * A user signed in by credentials in this session (audit NV-L3): has a user, not from a remember-me cookie and not
     * waiting for AuthKit's second factor. Third-party 2FA bundles: check {@see self::$tokenClass} as well.
     */
    public function isFullyAuthenticated(): bool
    {
        return $this->user instanceof AuthUserRef && !$this->rememberMe && !$this->secondFactorPending;
    }
}
