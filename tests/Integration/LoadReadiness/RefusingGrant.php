<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoadReadiness;

use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRefusal;
use Nowo\AuthKitBundle\Invitation\InvitationRefusedException;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_starts_with;

/**
 * A host grant that refuses the invitations of `refuse…` addresses after the account was written (1.50.0 tests).
 */
final class RefusingGrant implements InvitationGrantInterface
{
    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
        if (str_starts_with($invitation->email, 'refuse')) {
            throw InvitationRefusedException::because(new InvitationRefusal('host.no_seat_left'));
        }
    }
}
