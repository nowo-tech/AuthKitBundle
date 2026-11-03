<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * Demo user checker: accounts holding ROLE_DISABLED cannot sign in, and the account switcher refuses them.
 */
final class DemoUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (in_array('ROLE_DISABLED', $user->getRoles(), true)) {
            throw new CustomUserMessageAccountStatusException('This account is disabled.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
