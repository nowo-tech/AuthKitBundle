<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\Event;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * After a switch: the request's session is now the target account's (its id regenerated) and the token storage holds
 * its token. Hosts reset per-account context here (Beacon: organisation context) and audit on both accounts.
 */
final class AccountSwitchedEvent extends AccountEvent
{
    /**
     * @param UserInterface|null $from not public since 2.0: read fromRef()
     * @param UserInterface $to not public since 2.0: read toRef()
     */
    public function __construct(string $profile, string $account, Request $request, private readonly ?UserInterface $from, private readonly UserInterface $to)
    {
        parent::__construct($profile, $account, $request);
    }

    /**
     * The account the browser leaves, framework-free (1.46.0).
     */
    public function fromRef(): ?AuthUserRef
    {
        return AuthUserRefs::fromNullableUser($this->from);
    }

    /**
     * The target account, framework-free (1.46.0).
     */
    public function toRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->to);
    }
}
