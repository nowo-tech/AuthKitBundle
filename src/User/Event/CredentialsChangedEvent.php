<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User\Event;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
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
    public function __construct(
        public UserInterface $user,
        public bool $passwordChanged = true,
    ) {
    }
}
