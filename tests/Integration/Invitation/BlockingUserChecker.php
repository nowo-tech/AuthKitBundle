<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses the sign-in of `blocked@…` accounts (the accept page then sends them to the sign-in page).
 */
final class BlockingUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (str_starts_with($user->getUserIdentifier(), 'blocked@')) {
            throw new CustomUserMessageAccountStatusException('Blocked.');
        }
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
    }
}
