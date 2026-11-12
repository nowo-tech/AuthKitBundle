<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentationProviderInterface;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see AccountPresentationProviderInterface} (1.46.0) where the bundle asks the deprecated
 * {@see AccountPresenterInterface}. Built per call by {@see Ports}.
 *
 * @internal
 */
final readonly class AccountPresentationGate implements AccountPresenterInterface
{
    public function __construct(private AccountPresentationProviderInterface $provider)
    {
    }

    public function present(UserInterface $user): AccountPresentation
    {
        return $this->provider->presentationFor(AuthUserRefs::fromUser($user));
    }
}
