<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Default presenter: the user identifier as name and secondary line, no avatar.
 *
 * 2.0: on the port {@see AccountPresentationProviderInterface} (it implemented the removed AccountPresenterInterface on
 * the user object; the reference carries the same identifier).
 */
final class IdentifierAccountPresenter implements AccountPresentationProviderInterface
{
    public function presentationFor(AuthUserRef $user): AccountPresentation
    {
        return new AccountPresentation($user->identifier, $user->identifier);
    }
}
