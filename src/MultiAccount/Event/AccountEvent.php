<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base of the account-switcher events (1.34): the profile, the account's public id in this browser and the request.
 * Events never carry vault tokens, session ids or hashes.
 */
abstract class AccountEvent extends Event
{
    public function __construct(
        public readonly string $profile,
        public readonly string $account,
        public readonly Request $request,
    ) {
    }
}
