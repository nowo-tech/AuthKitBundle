<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\Controller\InvitationAcceptController;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\Tests\Support\Ports\TwinRulePorts;
use Nowo\AuthKitBundle\Tests\Support\Ports\UserDirectoryHost;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 2.0: the 1.46 ports are the only ones. The pass checks that whatever serves a port implements it (built-ins
 * included); the removed 1.x interfaces are refused by {@see RemovedPortsPassTest}. Fails on any deprecation.
 */
final class AuthKitPortsPassTest extends TestCase
{
    public function testTheBuiltInsServeThePorts(): void
    {
        $container = $this->container();
        (new AuthKitPortsPass())->process($container);

        self::assertSame(DoctrineUserLookup::class, (string) $container->getAlias(UserDirectoryInterface::class));
        self::assertSame(NullQrLoginStepUp::class, (string) $container->getAlias(QrLoginStepUpPolicyInterface::class));
    }

    public function testAnApplicationPortServiceIsAccepted(): void
    {
        $container = $this->container();
        $container->register('app.directory', UserDirectoryHost::class);
        $container->setAlias(UserDirectoryInterface::class, 'app.directory');
        $container->register('app.factory', stdClass::class)->setFactory(['Factory', 'create']);
        $container->setAlias(QrLoginStepUpPolicyInterface::class, 'app.factory');
        (new AuthKitPortsPass())->process($container);

        self::assertSame('app.directory', (string) $container->getAlias(UserDirectoryInterface::class));
    }

    public function testAPortWhoseClassDoesNotImplementItFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('app.directory', stdClass::class);
        $container->setAlias(UserDirectoryInterface::class, 'app.directory');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "app.directory" (stdClass) behind "' . UserDirectoryInterface::class . '" must implement it.');
        (new AuthKitPortsPass())->process($container);
    }

    public function testPerProfileServicesMustImplementThePort(): void
    {
        $container = $this->container();
        $container->register('app.twin', TwinRulePorts::class);
        $container->register('app.factory', stdClass::class)->setFactory(['Factory', 'create']);
        $container->setParameter(AuthKitPortsPass::SERVICES_PARAMETER, [
            ['mfa_policy', 'app.twin', 'nowo_auth_kit.profiles.default.mfa.policy'],
            ['enrolment_gate', 'app.twin', 'nowo_auth_kit.profiles.default.multi_account.enrolment_gate'],
            ['account_presenter', 'app.factory', 'nowo_auth_kit.profiles.default.multi_account.presenter'],
            ['account_presenter', 'app.missing', 'nowo_auth_kit.profiles.default.multi_account.presenter'],
            ['unknown_port', 'app.twin'],
            'not an entry',
        ]);
        (new AuthKitPortsPass())->process($container);
        self::assertFalse($container->hasParameter(AuthKitPortsPass::SERVICES_PARAMETER));

        $container = $this->container();
        $container->register('app.nothing', stdClass::class);
        $container->setParameter(AuthKitPortsPass::SERVICES_PARAMETER, [['invitations_access_checker', 'app.nothing', 'nowo_auth_kit.profiles.default.invitations.security.access_checker']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_auth_kit: the service "app.nothing" (stdClass) of nowo_auth_kit.profiles.default.invitations.security.access_checker must implement "Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface".');
        (new AuthKitPortsPass())->process($container);
    }

    public function testAnEntryWithoutAPathNamesThePort(): void
    {
        $container = $this->container();
        $container->register('app.nothing', stdClass::class);
        $container->setParameter(AuthKitPortsPass::SERVICES_PARAMETER, [['mfa_policy', 'app.nothing']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('of mfa_policy must implement');
        (new AuthKitPortsPass())->process($container);
    }

    public function testTaggedServicesMustImplementThePort(): void
    {
        $container = $this->container();
        $container->register('app.twin_grant', TwinRulePorts::class)->addTag(InvitationGrantHandlerInterface::TAG);
        $container->register('app.twin_policy', TwinRulePorts::class)->addTag(InvitationActionPolicyInterface::AUTHORIZER_TAG);
        $container->register('app.twin_step', TwinRulePorts::class)->addTag('nowo_auth_kit.social_callback_handler');
        // AuthKit's own acceptance page owns the callback through the internal handler interface.
        $container->register(InvitationAcceptController::class, InvitationAcceptController::class)->addTag('nowo_auth_kit.social_callback_handler');
        (new AuthKitPortsPass())->process($container);

        $container->register('app.bad', stdClass::class)->addTag(InvitationActionPolicyInterface::AUTHORIZER_TAG);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('of the tag "nowo_auth_kit.invitation_action_authorizer" must implement "' . InvitationActionPolicyInterface::class . '"');
        (new AuthKitPortsPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(DoctrineUserLookup::class, DoctrineUserLookup::class);
        $container->setAlias(UserDirectoryInterface::class, DoctrineUserLookup::class);
        $container->register(NullQrLoginStepUp::class, NullQrLoginStepUp::class);
        $container->setAlias(QrLoginStepUpPolicyInterface::class, NullQrLoginStepUp::class);

        return $container;
    }
}
