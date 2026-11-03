<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_array;
use function sprintf;

/**
 * Native MFA (1.34, audit DD-L3) fails closed without a lock factory: second-factor attempts are consumed with a
 * check-and-increment that is atomic only under symfony/lock. Without it, concurrent guesses could exceed
 * `mfa.rate_limit` and `max_attempts`.
 */
final class MfaLockRequiredPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $profiles = $container->hasParameter('nowo_auth_kit.profiles') ? $container->getParameter('nowo_auth_kit.profiles') : null;
        if (!is_array($profiles) || $container->has('lock.factory')) {
            return;
        }
        foreach ($profiles as $name => $profile) {
            if (is_array($profile) && ($profile['mfa']['enabled'] ?? false) === true) {
                throw new LogicException(sprintf('NowoAuthKitBundle: profiles.%s.mfa requires symfony/lock (the "lock.factory" service, framework.lock) so second-factor attempt limits are atomic. Run "composer require symfony/lock" (use a shared store such as Redis or a database on several hosts), or disable MFA.', $name));
            }
        }
    }
}
