<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An account left this browser's switcher (`user` is null when it no longer loads).
 */
final class AccountRemovedEvent extends AccountEvent
{
    public function __construct(string $profile, string $account, Request $request, public readonly ?UserInterface $user, public readonly AccountRemovalReason $reason)
    {
        parent::__construct($profile, $account, $request);
    }
}
