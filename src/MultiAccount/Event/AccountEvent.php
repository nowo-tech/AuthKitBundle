<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Nowo\AuthKitBundle\Port\AuthRequestContexts;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base of the account-switcher events (1.34): the profile, the account's public id in this browser and the request.
 * Events never carry vault tokens, session ids or hashes.
 */
abstract class AccountEvent extends Event
{
    /**
     * @param Request $request @deprecated since 1.46.0, use requestContext(); removed in 2.0
     */
    public function __construct(
        public readonly string $profile,
        public readonly string $account,
        public readonly Request $request,
    ) {
    }

    /**
     * The request, framework-free (1.46.0); its `profile` is the event's profile.
     */
    public function requestContext(): AuthRequestContext
    {
        return AuthRequestContexts::fromRequest($this->request, null, $this->profile);
    }

    /**
     * The `$profile` value (1.55.0): read it here; same value as the property.
     */
    public function profile(): string
    {
        return $this->profile;
    }

    /**
     * The `$account` value (1.55.0): read it here; same value as the property.
     */
    public function account(): string
    {
        return $this->account;
    }

    /**
     * The `$request` value (1.55.0).
     *
     * @deprecated since 1.55.0, use requestContext(); removed in 2.0 with the framework-typed property (REQ-ARCH-001 rule 3)
     */
    public function request(): Request
    {
        return $this->request;
    }
}
