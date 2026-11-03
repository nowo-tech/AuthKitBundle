<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An account joined this browser's switcher after its own complete sign-in.
 */
final class AccountAddedEvent extends AccountEvent
{
    public function __construct(string $profile, string $account, Request $request, public readonly UserInterface $user)
    {
        parent::__construct($profile, $account, $request);
    }
}
