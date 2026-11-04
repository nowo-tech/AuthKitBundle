<?php

declare(strict_types=1);

namespace App\Security;

use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Demo account presenter (multi_account.presenter, AuthKit 1.35 avatars): the identifier as name, like the default,
 * plus an avatar URL for some demo users: carol a same-origin picture, ada (the demo administrator) a broken URL (the switcher falls back to the
 * initials) and dave an http: URL the avatar policy drops.
 */
final class DemoAccountPresenter implements AccountPresenterInterface
{
    private const AVATARS = [
        'carol@example.com' => '/images/avatar-carol.svg',
        'ada@example.com'   => '/does-not-exist.webp',
        'dave@example.com'  => 'http://example.com/dave.png',
    ];

    public function present(UserInterface $user): AccountPresentation
    {
        $identifier = $user->getUserIdentifier();

        return new AccountPresentation($identifier, $identifier, self::AVATARS[$identifier] ?? null);
    }
}
