<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * When login_throttle_required is true, missing Login Throttle Bundle fails compilation.
 */
final class LoginThrottleRequiredPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_auth_kit.login_throttle_required')) {
            return;
        }

        if (!(bool) $container->getParameter('nowo_auth_kit.login_throttle_required')) {
            return;
        }

        if ($container->hasExtension('nowo_login_throttle')) {
            return;
        }

        throw new InvalidConfigurationException('nowo_auth_kit.login_throttle_required is true but nowo-tech/login-throttle-bundle is not registered. Install it (`composer require nowo-tech/login-throttle-bundle`) and run `php bin/console nowo:login-throttle:configure-security`, or set login_throttle_required: false for local demos only. See docs/UPGRADING.md (1.17.4).');
    }
}
