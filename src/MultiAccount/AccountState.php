<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One account of the switcher (1.34): `active` (this session), `signed_in` (parked, its session alive) or
 * `signed_out` (parked, its session expired but it can come back through its remember-me cookie).
 */
final readonly class AccountState
{
    public const ACTIVE = 'active';

    public const SIGNED_IN = 'signed_in';

    public const SIGNED_OUT = 'signed_out';

    /**
     * @param UserInterface $user @deprecated since 1.46.0, use userRef(); removed in 2.0
     */
    public function __construct(
        public string $id,
        public UserInterface $user,
        public AccountPresentation $presentation,
        public string $state,
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }

    /**
     * The account, framework-free (1.46.0, REQ-ARCH-001 rule 3).
     */
    public function userRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->user);
    }

    /**
     * The `$id` value (1.55.0): read it here; same value as the property.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The `$user` value (1.55.0).
     *
     * @deprecated since 1.55.0, use userRef(); removed in 2.0 with the framework-typed property (REQ-ARCH-001 rule 3)
     */
    public function user(): UserInterface
    {
        return $this->user;
    }

    /**
     * The `$presentation` value (1.55.0): read it here; same value as the property.
     */
    public function presentation(): AccountPresentation
    {
        return $this->presentation;
    }

    /**
     * The `$state` value (1.55.0): read it here; same value as the property.
     */
    public function state(): string
    {
        return $this->state;
    }
}
