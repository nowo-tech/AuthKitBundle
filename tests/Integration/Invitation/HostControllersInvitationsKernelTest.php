<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * REQ-SF-004 rule 7: with invitations enabled, a host whose routing is only `routing.controllers` gets none of the
 * invitation routes (accept page, administration): they exist only through the bundle route loader import.
 */
final class HostControllersInvitationsKernelTest extends TestCase
{
    public function testRoutingControllersAloneRegistersNoInvitationRoute(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        $kernel = new HostControllersInvitationsKernel('test', true);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $routes = $router->getRouteCollection();
            self::assertNull($routes->get('nowo_auth_kit_invitation_accept'));
            self::assertNull($routes->get('nowo_auth_kit_invitations'));
            foreach ($routes->all() as $name => $route) {
                $controller = $route->getDefault('_controller');
                self::assertFalse(
                    is_string($controller) && str_contains($controller, 'Invitation'),
                    sprintf('Route "%s" points to an invitation controller without the bundle routing import.', $name),
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
