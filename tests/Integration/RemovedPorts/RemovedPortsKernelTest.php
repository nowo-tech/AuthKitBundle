<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\RemovedPorts;

use LogicException;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\RemovedPortsPass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 2.0 (REQ-ARCH-001 rule 3, audit lesson W1-1 / XR-WF-M1): through the real NowoAuthKitBundle::build(), an application
 * service still on a port interface removed in 2.0 fails the container build with a message naming the service, the
 * removed interface and its replacement. Without the check Symfony would drop it silently (an autodiscovered class that
 * cannot be loaded, a decorator or an alias of a missing id) and AuthKit would fall back to its default: a host
 * restriction on QR approval, invitation actions or the MFA requirement would disappear (wider access), a host user
 * port would be replaced by the Doctrine one. Fixtures: tests/Fixtures/RemovedPorts (one directory per case).
 */
final class RemovedPortsKernelTest extends TestCase
{
    private const FIXTURES = 'Nowo\AuthKitBundle\Tests\Fixtures\RemovedPorts\\';

    /**
     * @return iterable<string, array{string, string, string}> fixture directory, the service the message names, the removed interface
     */
    public static function removedPorts(): iterable
    {
        yield '#[AsDecorator] of the QR step-up id' => ['DecoratorOfQrStepUp', self::FIXTURES . 'DecoratorOfQrStepUp\RestrictedStepUp', 'Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface'];
        yield '#[AsDecorator] of the invitation action authorizer id' => ['DecoratorOfActionAuthorizer', self::FIXTURES . 'DecoratorOfActionAuthorizer\RestrictedActions', 'Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface'];
        yield 'a loadable #[AsDecorator] of the MFA policy id, onInvalid: IGNORE' => ['IgnoredDecoratorOfMfaPolicy', self::FIXTURES . 'IgnoredDecoratorOfMfaPolicy\RequiredMfa', 'Nowo\AuthKitBundle\Mfa\MfaPolicyInterface'];
        yield '#[AsAlias] of the user lookup id' => ['AliasOfUserLookup', self::FIXTURES . 'AliasOfUserLookup\HostUsers', 'Nowo\AuthKitBundle\User\UserLookupInterface'];
        yield 'a class implementing only the user lookup' => ['ImplementsUserLookup', self::FIXTURES . 'ImplementsUserLookup\OldUsers', 'Nowo\AuthKitBundle\User\UserLookupInterface'];
        yield 'a class implementing only the invitation grant' => ['ImplementsGrant', self::FIXTURES . 'ImplementsGrant\OldGrant', 'Nowo\AuthKitBundle\Invitation\InvitationGrantInterface'];
        yield 'the port id aliased to a class implementing only the removed interface' => ['AliasedToThePort', self::FIXTURES . 'AliasedToThePort\OldStepUp', 'Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface'];
    }

    #[DataProvider('removedPorts')]
    public function testAServiceOnARemovedPortFailsTheBuild(string $fixture, string $service, string $removed): void
    {
        $kernel = new RemovedPortsTestKernel($fixture);
        try {
            $kernel->boot();
            self::fail('the build must fail');
        } catch (LogicException $failure) {
            self::assertSame(RemovedPortsPass::message($service, $removed), $failure->getMessage());
            self::assertStringContainsString('deprecated in 1.46.0 and removed in 2.0', $failure->getMessage());
            self::assertStringContainsString(RemovedPortsPass::REMOVED[$removed], $failure->getMessage(), 'the replacement is named');
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAnUnrelatedMissingInterfaceKeepsItsLazyErrorAndTheBuildSucceeds(): void
    {
        $kernel = new RemovedPortsTestKernel('UnrelatedMissing');
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertFalse($container->has(self::FIXTURES . 'UnrelatedMissing\PolicyAdapter'), 'Symfony dropped the unused class as before');
        } finally {
            $kernel->shutdown();
        }
    }
}
