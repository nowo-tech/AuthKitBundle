<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the AuthKit routes are opt-in. A host whose routing is only the Symfony 7.3+ default
 * `controllers: { resource: routing.controllers }` must not get any AuthKit route; they exist only where the host
 * imports `@NowoAuthKitBundle/Resources/config/routing.yaml` (profile route loader).
 */
final class HostControllersRoutesKernelTest extends TestCase
{
    public function testRoutingControllersAloneRegistersNoAuthKitRoute(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $kernel = new HostControllersRoutesKernel('test', true);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            foreach ($router->getRouteCollection()->all() as $name => $route) {
                $controller = $route->getDefault('_controller');
                self::assertFalse(str_starts_with($name, 'nowo_auth_kit'), sprintf('Route "%s" must only exist when the host imports the bundle routing.yaml.', $name));
                self::assertFalse(
                    is_string($controller) && str_starts_with($controller, 'Nowo\\AuthKitBundle\\Controller\\'),
                    sprintf('Route "%s" points to an AuthKit controller without the bundle routing import.', $name),
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
