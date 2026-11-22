<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Tests\Support\Ports\TwinRulePorts;
use PHPUnit\Framework\TestCase;

use function interface_exists;

/**
 * 1.56.0 / 2.0: the tag and action constants are read on the ports, with the values of 1.x; the 1.x interfaces that
 * also carried them are removed in 2.0. A host class implementing the ports inherits them.
 */
final class PortConstantsTest extends TestCase
{
    public function testThePortsCarryTheConstants(): void
    {
        self::assertSame('nowo_auth_kit.invitation_action_authorizer', InvitationActionPolicyInterface::AUTHORIZER_TAG);
        self::assertSame(
            ['invite', 'reinvite', 'resend', 'revoke', 'return_link'],
            [InvitationActionPolicyInterface::INVITE, InvitationActionPolicyInterface::REINVITE, InvitationActionPolicyInterface::RESEND, InvitationActionPolicyInterface::REVOKE, InvitationActionPolicyInterface::RETURN_LINK],
        );
        self::assertSame('nowo_auth_kit.invitation_grant', InvitationGrantHandlerInterface::TAG);
        self::assertSame('revoke', TwinRulePorts::REVOKE);
        self::assertSame('nowo_auth_kit.invitation_grant', TwinRulePorts::TAG);
        self::assertFalse(interface_exists('Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface'));
        self::assertFalse(interface_exists('Nowo\AuthKitBundle\Invitation\InvitationGrantInterface'));
    }
}
