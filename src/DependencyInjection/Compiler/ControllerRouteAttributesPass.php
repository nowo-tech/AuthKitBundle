<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function str_starts_with;

/**
 * REQ-SF-004: the AuthKit controllers declare #[Route], so autoconfiguration tags them `routing.controller`.
 * Their routes must only come from the `nowo_auth_kit` route loader (one copy per profile, with the profile
 * default and the locale variants): this pass removes the tag so an application importing
 * `resource: routing.controllers` (Symfony 7.3+ recipe) does not register them a second time without profile.
 *
 * Runs after the autoconfiguration passes (TYPE_BEFORE_OPTIMIZATION, priority 100) and before
 * RoutingControllerPass (priority 0).
 */
final class ControllerRouteAttributesPass implements CompilerPassInterface
{
    public const PRIORITY = 10;

    private const TAG = 'routing.controller';

    private const CONTROLLER_NAMESPACE = 'Nowo\\AuthKitBundle\\Controller\\';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $class = $container->getDefinition($id)->getClass() ?? $id;

            if (str_starts_with($class, self::CONTROLLER_NAMESPACE)) {
                $container->getDefinition($id)->clearTag(self::TAG);
            }
        }
    }
}
