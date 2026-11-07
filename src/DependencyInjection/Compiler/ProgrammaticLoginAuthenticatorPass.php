<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hands {@see ProgrammaticLoginAuthenticator} the per-firewall authenticator locators of `security.helper`: the same
 * list `Security::login()` counts.
 */
final class ProgrammaticLoginAuthenticatorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ProgrammaticLoginAuthenticator::class) && $container->hasDefinition('security.helper')) {
            $container->getDefinition(ProgrammaticLoginAuthenticator::class)
                ->setArgument('$authenticators', $container->getDefinition('security.helper')->getArgument(1));
        }
    }
}
