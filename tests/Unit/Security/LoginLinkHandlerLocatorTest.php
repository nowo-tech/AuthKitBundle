<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Nowo\AuthKitBundle\DependencyInjection\Compiler\LoginLinkHandlerLocatorPass;
use Nowo\AuthKitBundle\Security\LoginLinkHandlerLocator;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/** SEC-004 L2: login links are signed and consumed by the handler of the profile's own firewall. */
final class LoginLinkHandlerLocatorTest extends TestCase
{
    public function testReturnsTheHandlerOfTheRequestedFirewallOnly(): void
    {
        $admin   = $this->createMock(LoginLinkHandlerInterface::class);
        $locator = new LoginLinkHandlerLocator(new ServiceLocator([
            'admin'  => static fn (): LoginLinkHandlerInterface => $admin,
            'broken' => static fn (): stdClass => new stdClass(),
        ]));

        self::assertSame($admin, $locator->get('admin'));
        self::assertNull($locator->get('main'));
        self::assertNull($locator->get('broken'));
    }

    public function testPassMapsEveryFirewallHandler(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(LoginLinkHandlerLocator::class, new Definition(LoginLinkHandlerLocator::class));
        $container->setDefinition('security.authenticator.login_link_handler.main', new Definition(stdClass::class));
        $container->setAlias('security.authenticator.login_link_handler.admin', 'security.authenticator.login_link_handler.main');
        $container->setDefinition('unrelated', new Definition(stdClass::class));

        (new LoginLinkHandlerLocatorPass())->process($container);

        $argument = $container->getDefinition(LoginLinkHandlerLocator::class)->getArgument('$handlers');
        self::assertInstanceOf(Reference::class, $argument);
        $locator = $container->getDefinition((string) $argument);
        /** @var array<string, ServiceClosureArgument> $map */
        $map = $locator->getArgument(0);
        self::assertSame(['main', 'admin'], array_keys($map));
    }

    public function testPassIsANoOpWithoutTheLocatorService(): void
    {
        $container = new ContainerBuilder();
        (new LoginLinkHandlerLocatorPass())->process($container);

        self::assertFalse($container->hasDefinition(LoginLinkHandlerLocator::class));
    }
}
