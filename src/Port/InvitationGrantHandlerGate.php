<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Calls a host {@see InvitationGrantHandlerInterface} (1.46.0) where the bundle calls the deprecated
 * {@see InvitationGrantInterface}. Exceptions (a refusal) propagate. Built per call by {@see Ports}.
 *
 * @internal
 */
final readonly class InvitationGrantHandlerGate implements InvitationGrantInterface
{
    public function __construct(private InvitationGrantHandlerInterface $handler)
    {
    }

    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
        $this->handler->grantTo($invitation, AuthUserRefs::fromUser($user));
    }
}
