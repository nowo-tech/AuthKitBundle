<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Nowo\AuthKitBundle\Availability\FeatureAvailability;
use Nowo\AuthKitBundle\Availability\FeatureAvailabilityCheckerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Refuses to compile when a configured availability checker does not implement the interface: at runtime such a
 * service would silently read as "not available" ({@see FeatureAvailability}).
 * A service without a resolvable class (factory) is only checked at runtime.
 */
final class AvailabilityCheckersPass implements CompilerPassInterface
{
    public const PARAMETER = '.nowo_auth_kit.availability_checkers';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER)) {
            return;
        }
        $ids = $container->getParameter(self::PARAMETER);
        $container->getParameterBag()->remove(self::PARAMETER);

        foreach (is_array($ids) ? $ids : [] as $id) {
            $class = $container->getParameterBag()->resolveValue($container->findDefinition((string) $id)->getClass());
            if (is_string($class) && !is_a($class, FeatureAvailabilityCheckerInterface::class, true)) {
                throw new InvalidArgumentException(sprintf('The AuthKit availability checker "%s" must implement "%s".', $id, FeatureAvailabilityCheckerInterface::class));
            }
        }
    }
}
