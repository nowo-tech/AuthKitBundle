<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Controller\LogoutController;
use Nowo\AuthKitBundle\Controller\RegisterController;
use Nowo\AuthKitBundle\Controller\ResetPasswordCodeController;
use Nowo\AuthKitBundle\Controller\ResetPasswordController;
use Nowo\AuthKitBundle\Controller\ResetPasswordRequestController;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads login, logout, register, and password reset routes from bundle configuration.
 */
final class AuthKitRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly array $routes,
        private readonly string $passwordResetDelivery,
        private readonly bool $localeInPath,
        private readonly string $defaultLocale,
        private readonly array $enabledLocales,
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
            $this->createRoute(
                $this->routes['login']['path'],
                ['_controller' => LoginController::class . '::login'],
                ['GET', 'POST'],
            ),
        );

        $collection->add(
            $this->routes['logout']['name'],
            $this->createRoute(
                $this->routes['logout']['path'],
                ['_controller' => LogoutController::class . '::logout'],
                ['GET'],
            ),
        );

        $collection->add(
            $this->routes['register']['name'],
            $this->createRoute(
                $this->routes['register']['path'],
                ['_controller' => RegisterController::class . '::register'],
                ['GET', 'POST'],
            ),
        );

        $collection->add(
            $this->routes['reset_request']['name'],
            $this->createRoute(
                $this->routes['reset_request']['path'],
                ['_controller' => ResetPasswordRequestController::class . '::request'],
                ['GET', 'POST'],
            ),
        );

        $delivery = PasswordResetDeliveryMode::from($this->passwordResetDelivery);

        if ($delivery !== PasswordResetDeliveryMode::Code) {
            $collection->add(
                $this->routes['reset_password']['name'],
                $this->createRoute(
                    $this->routes['reset_password']['path'],
                    ['_controller' => ResetPasswordController::class . '::reset'],
                    ['GET', 'POST'],
                ),
            );
        }

        if ($delivery !== PasswordResetDeliveryMode::Link) {
            $collection->add(
                $this->routes['reset_password_code']['name'],
                $this->createRoute(
                    $this->routes['reset_password_code']['path'],
                    ['_controller' => ResetPasswordCodeController::class . '::complete'],
                    ['GET', 'POST'],
                ),
            );
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_auth_kit';
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     */
    private function createRoute(string $path, array $defaults, array $methods): Route
    {
        if (!$this->localeInPath) {
            return new Route($path, $defaults, [], [], '', [], $methods);
        }

        return new Route(
            '/{_locale}' . $path,
            ['_locale' => $this->defaultLocale] + $defaults,
            ['_locale' => implode('|', $this->enabledLocales)],
            [],
            '',
            [],
            $methods,
        );
    }
}
