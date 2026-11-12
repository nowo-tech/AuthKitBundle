<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Event;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatch this event (1.41) when your application changes a user's password or identifier outside Doctrine (domain
 * service, another store, an external IdP sync). AuthKit then does what its Doctrine `onFlush` listeners do for
 * entity changes: the user's MFA trusted devices are forgotten (reason `password_changed`) and the pending reset
 * credential is cleared through {@see PasswordResetTokenManagerInterface}.
 *
 * Dispatch it after your commit. With a Doctrine user entity the listeners already cover the change: do not dispatch
 * it as well.
 */
final readonly class CredentialsChangedEvent
{
    /**
     * @param UserInterface $user @deprecated since 1.46.0, use userRef(); removed in 2.0
     */
    public function __construct(
        public UserInterface $user,
        public bool $passwordChanged = true,
    ) {
    }

    /**
     * The user, framework-free (1.46.0, REQ-ARCH-001 rule 3).
     */
    public function userRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->user);
    }
}
