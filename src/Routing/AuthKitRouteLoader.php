<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Controller\LogoutController;
use Nowo\AuthKitBundle\Controller\RegisterController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads login, logout, and register routes from bundle configuration.
 */
final class AuthKitRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly array $routes,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('AuthKit routes already loaded.');
        }

        $this->loaded = true;
        $collection   = new RouteCollection();

        $collection->add(
            $this->routes['login']['name'],
            new Route(
                $this->routes['login']['path'],
                ['_controller' => LoginController::class . '::login'],
                methods: ['GET', 'POST'],
            ),
        );

        $collection->add(
            $this->routes['logout']['name'],
            new Route(
                $this->routes['logout']['path'],
                ['_controller' => LogoutController::class . '::logout'],
                methods: ['GET'],
            ),
        );

        $collection->add(
            $this->routes['register']['name'],
            new Route(
                $this->routes['register']['path'],
                ['_controller' => RegisterController::class . '::register'],
                methods: ['GET', 'POST'],
            ),
        );

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_auth_kit';
    }
}
