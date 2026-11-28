<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_a;
use function is_array;
use function sprintf;

/**
 * SAML (2.10.0): the single-use claim of a pending SAML request lives in the attempt limiter's cache pool
 * (`attempt_limiter.cache_pool`). A pool in process memory (`cache.adapter.array`, `ArrayAdapter`) is per worker and per
 * replica, so a captured response could be used once per process: outside the `test` environment the build fails
 * while a profile enables SAML with such a pool. A filesystem pool is shared by the workers of one host only; several
 * hosts need a shared store (Redis, database), which this pass cannot see.
 */
final class SamlSharedClaimPoolPass implements CompilerPassInterface
{
    private const MEMORY_ADAPTER = 'cache.adapter.array';

    public function process(ContainerBuilder $container): void
    {
        $profiles = $container->hasParameter('nowo_auth_kit.profiles') ? $container->getParameter('nowo_auth_kit.profiles') : null;
        if (!is_array($profiles) || !$container->hasDefinition(AuthKitAttemptLimiter::class)
            || ($container->hasParameter('kernel.environment') && $container->getParameter('kernel.environment') === 'test')) {
            return;
        }
        $pool = $container->getDefinition(AuthKitAttemptLimiter::class)->getArgument('$cache');
        foreach ($profiles as $name => $profile) {
            if (is_array($profile) && ($profile['saml']['mode'] ?? null) === 'enabled' && $pool instanceof Reference && $this->inMemory($container, (string) $pool)) {
                throw new LogicException(sprintf('NowoAuthKitBundle: profiles.%s.saml needs a shared attempt_limiter.cache_pool: "%s" keeps the single-use claim of SAML requests in process memory. Use a pool shared by every worker and host (Redis, database).', $name, $pool));
            }
        }
    }

    private function inMemory(ContainerBuilder $container, string $id): bool
    {
        while ($container->hasDefinition($id) || $container->hasAlias($id)) {
            if ($id === self::MEMORY_ADAPTER) {
                return true;
            }
            $definition = $container->findDefinition($id);
            $class      = $definition->getClass();
            if ($class !== null && is_a($class, ArrayAdapter::class, true)) {
                return true;
            }
            if (!$definition instanceof ChildDefinition) {
                return false;
            }
            $id = $definition->getParent();
        }

        return $id === self::MEMORY_ADAPTER;
    }
}
