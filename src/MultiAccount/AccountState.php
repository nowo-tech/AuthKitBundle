<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

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
}
