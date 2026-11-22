<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Nowo\AuthKitBundle\MultiAccount\AccountRemovalReason;
use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An account left this browser's switcher (`user` is null when it no longer loads).
 */
final class AccountRemovedEvent extends AccountEvent
{
    /**
     * @param UserInterface|null $user not public since 2.0: read userRef()
     */
    public function __construct(string $profile, string $account, Request $request, private readonly ?UserInterface $user, public readonly AccountRemovalReason $reason)
    {
        parent::__construct($profile, $account, $request);
    }

    /**
     * The account, framework-free (1.46.0); null when it no longer loads.
     */
    public function userRef(): ?AuthUserRef
    {
        return AuthUserRefs::fromNullableUser($this->user);
    }

    /**
     * The `$reason` value (1.55.0): read it here; same value as the property.
     */
    public function reason(): AccountRemovalReason
    {
        return $this->reason;
    }
}
