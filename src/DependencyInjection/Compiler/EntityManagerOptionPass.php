<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Validates `nowo_auth_kit.doctrine.entity_manager` (1.43.0, audit LD).
 *
 * The build fails when the configured entity manager does not exist or does not map the bundle entities.
 */
final class EntityManagerOptionPass implements CompilerPassInterface
{
    public const PARAMETER = 'nowo_auth_kit.doctrine.entity_manager';

    /** @var list<string> */
    private const ENTITY_NAMESPACES = ['Nowo\\AuthKitBundle\\Entity'];

    public function process(ContainerBuilder $container): void
    {
        $name = $container->hasParameter(self::PARAMETER) ? $container->getParameter(self::PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_auth_kit.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                $maps      = $maps || ($manager === $name && $this->isBundleNamespace($namespace, true));
                $calls[]   = $call;
            }
            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_auth_kit.doctrine.entity_manager) does not map %s.', $name, implode(', ', self::ENTITY_NAMESPACES)));
            }
        }
    }

    /**
     * @param bool $orParent also accept a parent namespace of the bundle entities (a host mapping of a wider namespace)
     */
    private function isBundleNamespace(mixed $namespace, bool $orParent): bool
    {
        if (!is_string($namespace)) {
            return false;
        }
        foreach (self::ENTITY_NAMESPACES as $bundleNamespace) {
            if (str_starts_with($namespace, $bundleNamespace) || ($orParent && str_starts_with($bundleNamespace, $namespace . '\\'))) {
                return true;
            }
        }

        return false;
    }
}
