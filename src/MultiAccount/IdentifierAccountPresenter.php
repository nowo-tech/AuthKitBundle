<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default presenter: the user identifier as name and secondary line, no avatar.
 */
final class IdentifierAccountPresenter implements AccountPresenterInterface
{
    public function present(UserInterface $user): AccountPresentation
    {
        return new AccountPresentation($user->getUserIdentifier(), $user->getUserIdentifier());
    }
}
