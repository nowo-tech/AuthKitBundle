<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentGateInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function strstr;
use function ucfirst;

/**
 * Host gate and presenter of the custom kernel: `support@` never joins the switcher (its own MFA is "pending");
 * accounts are shown as "Member Account" with an avatar.
 */
final class HostAccountPolicy implements EnrolmentGateInterface, AccountPresenterInterface
{
    public function isComplete(TokenInterface $token): bool
    {
        return $token->getUserIdentifier() !== 'support@example.com';
    }

    public function present(UserInterface $user): AccountPresentation
    {
        return new AccountPresentation(ucfirst((string) strstr($user->getUserIdentifier(), '@', true)) . ' Account', $user->getUserIdentifier(), '/avatars/' . $user->getUserIdentifier() . '.png');
    }
}
