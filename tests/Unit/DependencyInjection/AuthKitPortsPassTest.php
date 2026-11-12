<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AuthKitPortsPass;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Port\QrLoginStepUpGate;
use Nowo\AuthKitBundle\Port\UserDirectoryGate;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\Tests\Support\Ports\BothUsersHost;
use Nowo\AuthKitBundle\Tests\Support\Ports\LegacyRulePorts;
use Nowo\AuthKitBundle\Tests\Support\Ports\StepUpHost;
use Nowo\AuthKitBundle\Tests\Support\Ports\TwinRulePorts;
use Nowo\AuthKitBundle\Tests\Support\Ports\UserDirectoryHost;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class AuthKitPortsPassTest extends TestCase
{
    public function testWithoutTheTwinTheDeprecatedAliasesStayAsTheyAre(): void
    {
        $container = $this->container();
        (new AuthKitPortsPass())->process($container);

        self::assertSame(DoctrineUserLookup::class, (string) $container->getAlias(UserLookupInterface::class));
        self::assertSame(NullQrLoginStepUp::class, (string) $container->getAlias(QrLoginStepUpInterface::class));
        self::assertFalse($container->hasDefinition(AuthKitPortsPass::GATE_PREFIX . 'user_lookup'));
        self::assertFalse($container->hasParameter(AuthKitPortsPass::SERVICES_PARAMETER));
    }

    public function testAnApplicationTwinIsServedThroughAGateBehindTheDeprecatedId(): void
    {
        $container = $this->container();
        $container->register('app.directory', UserDirectoryHost::class);
        $container->setAlias(UserDirectoryInterface::class, 'app.directory');
        $container->register('app.step_up', StepUpHost::class);
        $container->setAlias(QrLoginStepUpPolicyInterface::class, 'app.step_up');
        (new AuthKitPortsPass())->process($container);

        self::assertSame(AuthKitPortsPass::GATE_PREFIX . 'user_lookup', (string) $container->getAlias(UserLookupInterface::class));
        $gate = $container->getDefinition(AuthKitPortsPass::GATE_PREFIX . 'user_lookup');
        self::assertSame(UserDirectoryGate::class, $gate->getClass());
        self::assertEquals([new Reference(UserDirectoryInterface::class)], $gate->getArguments());

        self::assertSame(AuthKitPortsPass::GATE_PREFIX . 'qr_step_up', (string) $container->getAlias(QrLoginStepUpInterface::class));
        $stepUp = $container->getDefinition(AuthKitPortsPass::GATE_PREFIX . 'qr_step_up');
        self::assertSame(QrLoginStepUpGate::class, $stepUp->getClass());
        self::assertSame(RequestProfileResolver::class, (string) $stepUp->getArgument(1));
        self::assertSame('security.token_storage', (string) $stepUp->getArgument(2));
    }

    public function testATwinDefinedAsAServiceIdAndAFactoryServiceAreAccepted(): void
    {
        $container = $this->container();
        $container->register(UserDirectoryInterface::class, UserDirectoryHost::class)->setFactory([UserDirectoryHost::class, 'create']);
        (new AuthKitPortsPass())->process($container);

        self::assertSame(AuthKitPortsPass::GATE_PREFIX . 'user_lookup', (string) $container->getAlias(UserLookupInterface::class));
    }

    public function testOneServiceImplementingBothKeepsTheDeprecatedWiring(): void
    {
        $container = $this->container();
        $container->register('app.users', BothUsersHost::class);
        $container->setAlias(UserDirectoryInterface::class, 'app.users');
        $container->setAlias(UserLookupInterface::class, 'app.users');
        (new AuthKitPortsPass())->process($container);

        self::assertSame('app.users', (string) $container->getAlias(UserLookupInterface::class));
        self::assertFalse($container->hasDefinition(AuthKitPortsPass::GATE_PREFIX . 'user_lookup'));
    }

    public function testBothInterfacesOnDifferentApplicationServicesFailTheBuild(): void
    {
        $container = $this->container();
        $container->register('app.directory', UserDirectoryHost::class);
        $container->setAlias(UserDirectoryInterface::class, 'app.directory');
        $container->register('app.lookup', BothUsersHost::class);
        $container->setAlias(UserLookupInterface::class, 'app.lookup');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('keep only one of them');
        (new AuthKitPortsPass())->process($container);
    }

    public function testATwinWhoseClassDoesNotImplementItFailsTheBuild(): void
    {
        $container = $this->container();
        $container->register('app.directory', stdClass::class);
        $container->setAlias(UserDirectoryInterface::class, 'app.directory');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The service "app.directory" (stdClass) behind "' . UserDirectoryInterface::class . '" must implement it.');
        (new AuthKitPortsPass())->process($container);
    }

    public function testPerProfileServicesMustImplementOneOfTheInterfaces(): void
    {
        $container = $this->container();
        $container->register('app.legacy', LegacyRulePorts::class);
        $container->register('app.twin', TwinRulePorts::class);
        $container->register('app.factory', stdClass::class)->setFactory(['Factory', 'create']);
        $container->setParameter(AuthKitPortsPass::SERVICES_PARAMETER, [
            ['mfa_policy', 'app.legacy', 'nowo_auth_kit.profiles.default.mfa.policy'],
            ['enrolment_gate', 'app.twin', 'nowo_auth_kit.profiles.default.multi_account.enrolment_gate'],
            ['account_presenter', 'app.factory', 'nowo_auth_kit.profiles.default.multi_account.presenter'],
            ['account_presenter', 'app.missing', 'nowo_auth_kit.profiles.default.multi_account.presenter'],
            ['unknown_port', 'app.legacy'],
            'not an entry',
        ]);
        (new AuthKitPortsPass())->process($container);
        self::assertFalse($container->hasParameter(AuthKitPortsPass::SERVICES_PARAMETER));

        $container = $this->container();
        $container->register('app.nothing', stdClass::class);
        $container->setParameter(AuthKitPortsPass::SERVICES_PARAMETER, [['invitations_access_checker', 'app.nothing', 'nowo_auth_kit.profiles.default.invitations.security.access_checker']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_auth_kit: the service "app.nothing" (stdClass) of nowo_auth_kit.profiles.default.invitations.security.access_checker must implement "Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface" (or the deprecated "Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface").');
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

    public function testTaggedServicesMustImplementOneOfTheInterfaces(): void
    {
        $container = $this->container();
        $container->register('app.grant', LegacyRulePorts::class)->addTag(InvitationGrantInterface::TAG);
        $container->register('app.twin_grant', TwinRulePorts::class)->addTag(InvitationGrantInterface::TAG);
        (new AuthKitPortsPass())->process($container);

        $container->register('app.bad', stdClass::class)->addTag('nowo_auth_kit.social_callback_handler');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('of the tag "nowo_auth_kit.social_callback_handler" must implement');
        (new AuthKitPortsPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(DoctrineUserLookup::class, DoctrineUserLookup::class);
        $container->setAlias(UserLookupInterface::class, DoctrineUserLookup::class);
        $container->register(NullQrLoginStepUp::class, NullQrLoginStepUp::class);
        $container->setAlias(QrLoginStepUpInterface::class, NullQrLoginStepUp::class);

        return $container;
    }
}
