<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Routing;

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
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function array_map;
use function array_unique;
use function array_values;
use function is_bool;
use function strtoupper;

/**
 * Loads login, logout, register, password reset, and magic login routes from bundle configuration.
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

        $this->addAuthRoute(
            $collection,
            $routes['login']['name'],
            $routes['login']['path'],
            ['_controller' => LoginController::class . '::login'] + $profileDefaults,
            ['GET', 'POST'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['logout']['name'],
            $routes['logout']['path'],
            ['_controller' => LogoutController::class . '::logout'] + $profileDefaults,
            $this->logoutMethods($routes['logout']),
        );

        if ($this->featureRoutesEnabled($profile, 'registration_mode')) {
            $this->addAuthRoute(
                $collection,
                $routes['register']['name'],
                $routes['register']['path'],
                ['_controller' => RegisterController::class . '::register'] + $profileDefaults,
                ['GET', 'POST'],
            );
        }

        if ($this->featureRoutesEnabled($profile, 'password_reset')) {
            $this->loadPasswordResetRoutes($collection, $routes, $profileDefaults, $delivery, $tokenInSession);
        }

        if ($this->featureRoutesEnabled($profile, 'magic_login')) {
            $this->loadMagicLoginRoutes($collection, $routes, $profileDefaults, (bool) ($profile['magic_login']['confirm_interstitial'] ?? false));
        }

        if ($this->featureRoutesEnabled($profile, 'social_login')) {
            $this->loadSocialLoginRoutes($collection, $routes, $profileDefaults);
        }

        if ($this->featureRoutesEnabled($profile, 'qr_login')) {
            $this->loadQrLoginRoutes($collection, $routes, $profileDefaults);
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
        $this->addAuthRoute(
            $collection,
            $routes['reset_request']['name'],
            $routes['reset_request']['path'],
            ['_controller' => ResetPasswordRequestController::class . '::request'] + $profileDefaults,
            ['GET', 'POST'],
        );

        if ($delivery !== PasswordResetDeliveryMode::Code) {
            $this->addAuthRoute(
                $collection,
                $routes['reset_password']['name'],
                $routes['reset_password']['path'],
                // token_in_session: the token-less URL (token = null) renders the form with the session token.
                ['_controller' => ResetPasswordController::class . '::reset'] + ($tokenInSession ? ['token' => null] : []) + $profileDefaults,
                ['GET', 'POST'],
            );
        }

        if ($delivery !== PasswordResetDeliveryMode::Link) {
            $this->addAuthRoute(
                $collection,
                $routes['reset_password_code']['name'],
                $routes['reset_password_code']['path'],
                ['_controller' => ResetPasswordCodeController::class . '::complete'] + $profileDefaults,
                ['GET', 'POST'],
            );
        }
    }

    /**
     * @param array<string, array{path: string, name: string, methods?: list<string>}> $routes
     * @param array<string, mixed> $profileDefaults
     */
    private function loadMagicLoginRoutes(RouteCollection $collection, array $routes, array $profileDefaults, bool $confirmInterstitial): void
    {
        $this->addAuthRoute(
            $collection,
            $routes['magic_login_request']['name'],
            $routes['magic_login_request']['path'],
            ['_controller' => MagicLoginRequestController::class . '::request'] + $profileDefaults,
            ['GET', 'POST'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['magic_login_check']['name'],
            $routes['magic_login_check']['path'],
            [
                '_controller' => $confirmInterstitial
                    ? MagicLoginConfirmController::class . '::check'
                    : MagicLoginCheckController::class . '::check',
            ] + $profileDefaults,
            ['GET'],
        );

        if ($confirmInterstitial) {
            $this->addAuthRoute(
                $collection,
                $routes['magic_login_confirm']['name'],
                $routes['magic_login_confirm']['path'],
                ['_controller' => MagicLoginConfirmController::class . '::confirm'] + $profileDefaults,
                ['POST'],
            );
        }
    }

    /**
     * @param array<string, array{path: string, name: string, methods?: list<string>}> $routes
     * @param array<string, mixed> $profileDefaults
     */
    private function loadSocialLoginRoutes(RouteCollection $collection, array $routes, array $profileDefaults): void
    {
        $this->addAuthRoute(
            $collection,
            $routes['social_login_start']['name'],
            $routes['social_login_start']['path'],
            ['_controller' => SocialLoginStartController::class . '::start'] + $profileDefaults,
            ['GET'],
            ['provider' => '[a-z0-9_\\-]+'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['social_login_check']['name'],
            $routes['social_login_check']['path'],
            ['_controller' => SocialLoginCheckController::class . '::check'] + $profileDefaults,
            ['GET'],
            ['provider' => '[a-z0-9_\\-]+'],
        );
    }

    /**
     * @param array<string, array{path: string, name: string, methods?: list<string>}> $routes
     * @param array<string, mixed> $profileDefaults
     */
    private function loadQrLoginRoutes(RouteCollection $collection, array $routes, array $profileDefaults): void
    {
        $this->addAuthRoute(
            $collection,
            $routes['qr_login_start']['name'],
            $routes['qr_login_start']['path'],
            ['_controller' => QrLoginStartController::class . '::start'] + $profileDefaults,
            ['GET'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['qr_login_show']['name'],
            $routes['qr_login_show']['path'],
            ['_controller' => QrLoginShowController::class . '::show'] + $profileDefaults,
            ['GET'],
            ['id' => '[0-9a-f\\-]{36}'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['qr_login_status']['name'],
            $routes['qr_login_status']['path'],
            ['_controller' => QrLoginStatusController::class . '::status'] + $profileDefaults,
            ['GET'],
            ['id' => '[0-9a-f\\-]{36}'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['qr_login_complete']['name'],
            $routes['qr_login_complete']['path'],
            ['_controller' => QrLoginCompleteController::class . '::complete'] + $profileDefaults,
            ['GET'],
            ['id' => '[0-9a-f\\-]{36}'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['qr_login_approve']['name'],
            $routes['qr_login_approve']['path'],
            ['_controller' => QrLoginApproveController::class . '::approve'] + $profileDefaults,
            ['GET', 'POST'],
            ['id' => '[0-9a-f\\-]{36}'],
        );

        $this->addAuthRoute(
            $collection,
            $routes['qr_login_deny']['name'],
            $routes['qr_login_deny']['path'],
            ['_controller' => QrLoginDenyController::class . '::deny'] + $profileDefaults,
            ['POST'],
            ['id' => '[0-9a-f\\-]{36}'],
        );
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
