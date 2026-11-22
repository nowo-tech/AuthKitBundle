<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Invitation;

use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Tests\Support\Ports\BothRulePorts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 1.56.0 (migration aid for 2.0): the tag and action constants are readable on the 1.46 ports with the same values as on
 * the deprecated interfaces, and a class implementing both interfaces still compiles (the constants are declared once).
 */
final class PortConstantsTest extends TestCase
{
    public function testThePortsCarryTheSameConstants(): void
    {
        foreach (['AUTHORIZER_TAG', 'INVITE', 'REINVITE', 'RESEND', 'REVOKE', 'RETURN_LINK'] as $name) {
            self::assertSame(
                (new ReflectionClass(InvitationActionAuthorizerInterface::class))->getConstant($name),
                (new ReflectionClass(InvitationActionPolicyInterface::class))->getConstant($name),
                $name,
            );
        }
        self::assertSame('nowo_auth_kit.invitation_action_authorizer', InvitationActionPolicyInterface::AUTHORIZER_TAG);
        self::assertSame('revoke', InvitationActionPolicyInterface::REVOKE);
        self::assertSame(InvitationGrantInterface::TAG, InvitationGrantHandlerInterface::TAG);
        self::assertSame('nowo_auth_kit.invitation_grant', InvitationGrantHandlerInterface::TAG);
    }

    public function testAClassImplementingBothInterfacesCompiles(): void
    {
        $both = new BothRulePorts();

        self::assertInstanceOf(InvitationActionPolicyInterface::class, $both);
        self::assertInstanceOf(InvitationActionAuthorizerInterface::class, $both);
        self::assertInstanceOf(InvitationGrantHandlerInterface::class, $both);
        self::assertInstanceOf(InvitationGrantInterface::class, $both);
        self::assertSame('invite', BothRulePorts::INVITE);
        self::assertSame('nowo_auth_kit.invitation_grant', BothRulePorts::TAG);
    }
}
