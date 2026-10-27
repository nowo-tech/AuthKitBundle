<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

use LogicException;
use Nowo\AuthKitBundle\Controller\LoginController;
use Nowo\AuthKitBundle\Controller\LogoutController;
use Nowo\AuthKitBundle\Controller\MagicLoginCheckController;
use Nowo\AuthKitBundle\Controller\MagicLoginConfirmController;
use Nowo\AuthKitBundle\Controller\MagicLoginRequestController;
use Nowo\AuthKitBundle\Controller\QrLoginApproveController;
use Nowo\AuthKitBundle\Controller\QrLoginCompleteController;
use Nowo\AuthKitBundle\Controller\QrLoginDenyController;
use Nowo\AuthKitBundle\Controller\QrLoginShowController;
use Nowo\AuthKitBundle\Controller\QrLoginStartController;
use Nowo\AuthKitBundle\Controller\QrLoginStatusController;
use Nowo\AuthKitBundle\Controller\RegisterController;
use Nowo\AuthKitBundle\Controller\ResetPasswordCodeController;
use Nowo\AuthKitBundle\Controller\ResetPasswordController;
use Nowo\AuthKitBundle\Controller\ResetPasswordRequestController;
use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Controller\SocialLoginStartController;
use Nowo\AuthKitBundle\Controller\UnlocalizedLocaleRedirectController;
use Nowo\AuthKitBundle\Enum\LocaleInPathMode;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Enum\UnlocalizedLocaleMode;
use Nowo\AuthKitBundle\Profile\RequestProfileResolver;
use ReflectionMethod;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function array_map;
use function array_unique;
use function array_values;
use function is_bool;
use function sprintf;
use function strtoupper;

/**
 * Loads the AuthKit routes of every profile (REQ-SF-004): each controller action declares its #[Route]
 * (default path and name, HTTP methods, requirements); the loader copies it once per profile with the
 * configured routes.<key>.path / .name, the profile default and the locale-in-path variants.
 */
final class AuthKitRouteLoader extends Loader
{
    /** Default suffix of the bare twin (locale.unlocalized_route_suffix). */
    public const UNLOCALIZED_ROUTE_SUFFIX = '_unlocalized';

    private readonly LocaleInPathMode $localeInPathMode;

    private readonly UnlocalizedLocaleMode $unlocalizedMode;

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly array $profiles,
        string|bool $localeInPath,
        private readonly string $defaultLocale,
        private readonly array $enabledLocales,
        string $unlocalizedMode = 'redirect',
        private readonly string $unlocalizedRouteSuffix = self::UNLOCALIZED_ROUTE_SUFFIX,
        private readonly bool $skipDisabledFeatures = false,
    ) {
        $this->localeInPathMode = $this->normalizeInPathMode($localeInPath);
        $this->unlocalizedMode  = UnlocalizedLocaleMode::from($unlocalizedMode);
    }

    /**
     * Stateless: the router may load this resource more than once in one process (cold cache rebuilt
     * after a route invalidation, a second router, long-running workers), and each load returns the
     * same collection built from configuration.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();

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
        /** @var array<string, array{path: string, name: string, methods?: list<string>}> $routes */
        $routes            = $profile['routes'];
        $passwordReset     = $profile['password_reset'];
        $profileDefaults   = [RequestProfileResolver::REQUEST_ATTRIBUTE => $profileName];
        $passwordResetMode = $passwordReset['delivery'] ?? 'link';
        $delivery          = PasswordResetDeliveryMode::from($passwordResetMode);
        $tokenInSession    = (bool) ($passwordReset['token_in_session'] ?? false);

        $this->addControllerRoute($collection, $routes['login'], LoginController::class, 'login', $profileDefaults);
        $this->addControllerRoute(
            $collection,
            $routes['logout'],
            LogoutController::class,
            'logout',
            $profileDefaults,
            $this->logoutMethods($routes['logout']),
        );

        if ($this->featureRoutesEnabled($profile, 'registration_mode')) {
            $this->addControllerRoute($collection, $routes['register'], RegisterController::class, 'register', $profileDefaults);
        }

        if ($this->featureRoutesEnabled($profile, 'password_reset')) {
            $this->loadPasswordResetRoutes($collection, $routes, $profileDefaults, $delivery, $tokenInSession);
        }

        if ($this->featureRoutesEnabled($profile, 'magic_login')) {
            $this->loadMagicLoginRoutes($collection, $routes, $profileDefaults, (bool) ($profile['magic_login']['confirm_interstitial'] ?? false));
        }

        if ($this->featureRoutesEnabled($profile, 'social_login')) {
            $this->addControllerRoute($collection, $routes['social_login_start'], SocialLoginStartController::class, 'start', $profileDefaults);
            $this->addControllerRoute($collection, $routes['social_login_check'], SocialLoginCheckController::class, 'check', $profileDefaults);
        }

        if ($this->featureRoutesEnabled($profile, 'qr_login')) {
            $this->addControllerRoute($collection, $routes['qr_login_start'], QrLoginStartController::class, 'start', $profileDefaults);
            $this->addControllerRoute($collection, $routes['qr_login_show'], QrLoginShowController::class, 'show', $profileDefaults);
            $this->addControllerRoute($collection, $routes['qr_login_status'], QrLoginStatusController::class, 'status', $profileDefaults);
            $this->addControllerRoute($collection, $routes['qr_login_complete'], QrLoginCompleteController::class, 'complete', $profileDefaults);
            $this->addControllerRoute($collection, $routes['qr_login_approve'], QrLoginApproveController::class, 'approve', $profileDefaults);
            $this->addControllerRoute($collection, $routes['qr_login_deny'], QrLoginDenyController::class, 'deny', $profileDefaults);
        }
    }

    /**
     * @param array<string, array{path: string, name: string, methods?: list<string>}> $routes
     * @param array<string, mixed> $profileDefaults
     */
    private function loadPasswordResetRoutes(
        RouteCollection $collection,
        array $routes,
        array $profileDefaults,
        PasswordResetDeliveryMode $delivery,
        bool $tokenInSession,
    ): void {
        $this->addControllerRoute($collection, $routes['reset_request'], ResetPasswordRequestController::class, 'request', $profileDefaults);

        if ($delivery !== PasswordResetDeliveryMode::Code) {
            // token_in_session: the token-less URL (token = null) renders the form with the session token.
            $this->addControllerRoute(
                $collection,
                $routes['reset_password'],
                ResetPasswordController::class,
                'reset',
                ($tokenInSession ? ['token' => null] : []) + $profileDefaults,
            );
        }

        if ($delivery !== PasswordResetDeliveryMode::Link) {
            $this->addControllerRoute($collection, $routes['reset_password_code'], ResetPasswordCodeController::class, 'complete', $profileDefaults);
        }
    }

    /**
     * @param array<string, array{path: string, name: string, methods?: list<string>}> $routes
     * @param array<string, mixed> $profileDefaults
     */
    private function loadMagicLoginRoutes(RouteCollection $collection, array $routes, array $profileDefaults, bool $confirmInterstitial): void
    {
        $this->addControllerRoute($collection, $routes['magic_login_request'], MagicLoginRequestController::class, 'request', $profileDefaults);
        $this->addControllerRoute(
            $collection,
            $routes['magic_login_check'],
            $confirmInterstitial ? MagicLoginConfirmController::class : MagicLoginCheckController::class,
            'check',
            $profileDefaults,
        );

        if ($confirmInterstitial) {
            $this->addControllerRoute($collection, $routes['magic_login_confirm'], MagicLoginConfirmController::class, 'confirm', $profileDefaults);
        }
    }

    /**
     * REQ-SF-004: the controller action's #[Route] attribute is the source of the route's HTTP methods and
     * requirements; the profile configuration (routes.<key>.path / .name, whose defaults equal the attribute)
     * sets the path and the name of each per-profile copy.
     *
     * @param array{path: string, name: string, methods?: list<string>} $route
     * @param class-string $controller
     * @param array<string, mixed> $defaults
     * @param list<string>|null $methods overrides the attribute methods (routes.logout.methods)
     */
    private function addControllerRoute(
        RouteCollection $collection,
        array $route,
        string $controller,
        string $action,
        array $defaults,
        ?array $methods = null,
    ): void {
        $attribute    = self::routeAttribute($controller, $action);
        $requirements = [];
        foreach ($attribute->requirements as $parameter => $pattern) {
            $requirements[(string) $parameter] = (string) $pattern;
        }

        $this->addAuthRoute(
            $collection,
            $route['name'],
            $route['path'],
            ['_controller' => $controller . '::' . $action] + $defaults,
            $methods ?? array_values($attribute->methods),
            $requirements,
        );
    }

    /**
     * @param class-string $controller
     */
    public static function routeAttribute(string $controller, string $action): RouteAttribute
    {
        $attributes = (new ReflectionMethod($controller, $action))->getAttributes(RouteAttribute::class);

        if ($attributes === []) {
            throw new LogicException(sprintf('%s::%s() must declare a #[Route] attribute.', $controller, $action));
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     * @param array<string, string> $requirements
     */
    private function addAuthRoute(
        RouteCollection $collection,
        string $name,
        string $path,
        array $defaults,
        array $methods,
        array $requirements = [],
    ): void {
        if ($this->localeInPathMode->registersLocalizedRoutes()) {
            $collection->add($name, $this->createLocalizedRoute($path, $defaults, $methods, $requirements));
        }

        if ($this->localeInPathMode === LocaleInPathMode::Never) {
            $collection->add($name, $this->createBareRoute($path, $defaults, $methods, $requirements));

            return;
        }

        if ($this->localeInPathMode !== LocaleInPathMode::Both) {
            return;
        }

        $bareName = $name . $this->unlocalizedRouteSuffix;

        if ($this->unlocalizedMode === UnlocalizedLocaleMode::Redirect) {
            $collection->add($bareName, $this->createBareRoute(
                $path,
                [
                    '_controller'               => UnlocalizedLocaleRedirectController::class . '::redirect',
                    '_auth_kit_canonical_route' => $name,
                ] + $defaults,
                $methods,
                $requirements,
            ));

            return;
        }

        $collection->add($bareName, $this->createBareRoute(
            $path,
            ['_locale' => $this->defaultLocale] + $defaults,
            $methods,
            $requirements,
        ));
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     * @param array<string, string> $requirements
     */
    private function createBareRoute(string $path, array $defaults, array $methods, array $requirements = []): Route
    {
        return new Route($path, $defaults, $requirements, [], '', [], $methods);
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     * @param array<string, string> $requirements
     */
    private function createLocalizedRoute(string $path, array $defaults, array $methods, array $requirements = []): Route
    {
        return new Route(
            '/{_locale}' . $path,
            ['_locale' => $this->defaultLocale] + $defaults,
            ['_locale' => implode('|', $this->enabledLocales)] + $requirements,
            [],
            '',
            [],
            $methods,
        );
    }

    /**
     * routing.skip_disabled_features: a feature whose mode is "disabled" registers no route.
     *
     * @param array<string, mixed> $profile
     */
    private function featureRoutesEnabled(array $profile, string $feature): bool
    {
        if (!$this->skipDisabledFeatures) {
            return true;
        }

        $mode = $feature === 'registration_mode'
            ? ($profile['registration_mode'] ?? null)
            : ($profile[$feature]['mode'] ?? null);

        return $mode !== 'disabled';
    }

    /**
     * @param array{path: string, name: string, methods?: list<string>} $route
     *
     * @return list<string>
     */
    private function logoutMethods(array $route): array
    {
        $methods = $route['methods'] ?? [];

        return $methods === [] ? ['GET'] : array_values(array_unique(array_map(strtoupper(...), $methods)));
    }

    private function normalizeInPathMode(string|bool $localeInPath): LocaleInPathMode
    {
        if (is_bool($localeInPath)) {
            return $localeInPath ? LocaleInPathMode::Always : LocaleInPathMode::Never;
        }

        return LocaleInPathMode::from($localeInPath);
    }
}
