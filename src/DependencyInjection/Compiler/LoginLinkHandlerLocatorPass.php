<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\Security\LoginLinkHandlerLocator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function array_merge;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Feeds {@see LoginLinkHandlerLocator} with the login link handler of every firewall that declares `login_link`.
 */
final class LoginLinkHandlerLocatorPass implements CompilerPassInterface
{
    private const PREFIX = 'security.authenticator.login_link_handler.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(LoginLinkHandlerLocator::class)) {
            return;
        }

        $handlers = [];
        foreach (array_merge(array_keys($container->getDefinitions()), array_keys($container->getAliases())) as $id) {
            if (str_starts_with($id, self::PREFIX)) {
                $handlers[substr($id, strlen(self::PREFIX))] = new Reference($id);
            }
        }

        $container->getDefinition(LoginLinkHandlerLocator::class)
            ->setArgument('$handlers', ServiceLocatorTagPass::register($container, $handlers));
    }
}
