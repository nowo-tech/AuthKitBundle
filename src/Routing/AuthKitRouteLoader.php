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
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
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
     * @param array<string, array<string, mixed>> $profiles
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly array $profiles,
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

        foreach ($this->profiles as $profileName => $profile) {
            $this->loadProfileRoutes($collection, $profileName, $profile);
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_auth_kit';
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function loadProfileRoutes(RouteCollection $collection, string $profileName, array $profile): void
    {
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes            = $profile['routes'];
        $passwordReset     = $profile['password_reset'];
        $profileDefaults   = [RequestProfileResolver::REQUEST_ATTRIBUTE => $profileName];
        $passwordResetMode = $passwordReset['delivery'] ?? 'link';
        $delivery          = PasswordResetDeliveryMode::from($passwordResetMode);

        $collection->add(
            $routes['login']['name'],
            $this->createRoute(
                $routes['login']['path'],
                ['_controller' => LoginController::class . '::login'] + $profileDefaults,
                ['GET', 'POST'],
            ),
        );

        $collection->add(
            $routes['logout']['name'],
            $this->createRoute(
                $routes['logout']['path'],
                ['_controller' => LogoutController::class . '::logout'] + $profileDefaults,
                ['GET'],
            ),
        );

        $collection->add(
            $routes['register']['name'],
            $this->createRoute(
                $routes['register']['path'],
                ['_controller' => RegisterController::class . '::register'] + $profileDefaults,
                ['GET', 'POST'],
            ),
        );

        $collection->add(
            $routes['reset_request']['name'],
            $this->createRoute(
                $routes['reset_request']['path'],
                ['_controller' => ResetPasswordRequestController::class . '::request'] + $profileDefaults,
                ['GET', 'POST'],
            ),
        );

        if ($delivery !== PasswordResetDeliveryMode::Code) {
            $collection->add(
                $routes['reset_password']['name'],
                $this->createRoute(
                    $routes['reset_password']['path'],
                    ['_controller' => ResetPasswordController::class . '::reset'] + $profileDefaults,
                    ['GET', 'POST'],
                ),
            );
        }

        if ($delivery !== PasswordResetDeliveryMode::Link) {
            $collection->add(
                $routes['reset_password_code']['name'],
                $this->createRoute(
                    $routes['reset_password_code']['path'],
                    ['_controller' => ResetPasswordCodeController::class . '::complete'] + $profileDefaults,
                    ['GET', 'POST'],
                ),
            );
        }
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
