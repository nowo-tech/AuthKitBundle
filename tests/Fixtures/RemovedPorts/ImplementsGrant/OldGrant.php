<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\ImplementsGrant;

use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 2.0 W1-1 fixture (autodiscovered): a host invitation grant that still implements only the removed interface.
 */
final class OldGrant implements InvitationGrantInterface
{
    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
    }
}
