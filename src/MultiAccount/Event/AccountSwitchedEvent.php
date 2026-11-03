<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * After a switch: the request's session is now the target account's (its id regenerated) and the token storage holds
 * its token. Hosts reset per-account context here (Beacon: organisation context) and audit on both accounts.
 */
final class AccountSwitchedEvent extends AccountEvent
{
    public function __construct(string $profile, string $account, Request $request, public readonly ?UserInterface $from, public readonly UserInterface $to)
    {
        parent::__construct($profile, $account, $request);
    }
}
