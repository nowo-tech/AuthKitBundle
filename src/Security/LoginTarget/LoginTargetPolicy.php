<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function explode;
use function in_array;
use function is_a;
use function is_array;
use function is_string;
use function method_exists;
use function parse_url;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Decides whether a post-login redirect target is an HTML page of this application (1.44, Beacon re-audit item 5).
 *
 * Checks, in order:
 * 1. Same origin (never overridable): the target is an absolute path (`/…`, not `//…` nor `/\…`) or an absolute
 *    `http(s)` URL of the request's own scheme, host and port; no user info, no backslash, no control character.
 * 2. Host voters ({@see LoginTargetVoterInterface}): the first boolean answer wins.
 * 3. Configured rules: denied path prefixes (whole segments), denied path patterns, denied route names and route-name
 *    patterns, the route option (`html: false`), a `_format` other than `html`, a controller whose declared return type
 *    is a JSON, streamed or file response, and (with `require_route`) a path that matches no GET route.
 *
 * Stateless: routes are matched with a GET copy of the router context, never by changing the shared one (worker safe).
 */
final readonly class LoginTargetPolicy
{
    /**
     * @var list<class-string>
     */
    private const NON_PAGE_RESPONSES = [JsonResponse::class, StreamedResponse::class, BinaryFileResponse::class];

    /**
     * @param iterable<LoginTargetVoterInterface> $voters
     * @param list<string> $deniedPathPrefixes
     * @param list<string> $deniedPathPatterns
     * @param list<string> $deniedRoutes
     * @param list<string> $deniedRoutePatterns
     */
    public function __construct(
        private RouterInterface $router,
        private iterable $voters = [],
        private array $deniedPathPrefixes = [],
        private array $deniedPathPatterns = [],
        private array $deniedRoutes = [],
        private array $deniedRoutePatterns = [],
        private ?string $routeOption = 'html',
        private bool $requireRoute = true,
        private bool $checkControllerResponse = true,
    ) {
    }

    /**
     * `$target` (a `Location` value or a saved `target_path`) is a same-origin HTML page reachable with GET.
     */
    public function allows(string $target, Request $request, string $firewall): bool
    {
        $parts = self::sameOriginParts($target, $request);
        if ($parts === null) {
            return false;
        }
        [$path, $query] = $parts;
        $routeName      = $this->matchGetRoute($path);
        $loginTarget    = new LoginTarget($path, $query, $routeName, $firewall);
        foreach ($this->voters as $voter) {
            $vote = $voter->vote($loginTarget);
            if ($vote !== null) {
                return $vote;
            }
        }

        return $this->passesRules($loginTarget);
    }

    /**
     * Path (without the base URL) and query of a same-origin target, or null when the target is not same-origin or
     * not a safe absolute path.
     *
     * @return array{0: string, 1: string|null}|null
     */
    public static function sameOriginParts(string $target, Request $request): ?array
    {
        if ($target === '' || str_contains($target, '\\') || preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            return null;
        }
        $parts = parse_url($target);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (!isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $origin = sprintf('%s://%s', strtolower($parts['scheme']), strtolower($parts['host']));
            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            if ($origin !== strtolower($request->getSchemeAndHttpHost())) {
                return null;
            }
        } elseif (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }
        // parse_url() omits an empty path, it never returns ''.
        $path = $parts['path'] ?? '/';
        if (str_starts_with($path, '//')) {
            return null;
        }
        $base = $request->getBaseUrl();
        if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base));
            $path = $path === '' ? '/' : $path;
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? $parts['query'] : null;

        return [$path, $query];
    }

    private function passesRules(LoginTarget $target): bool
    {
        if ($this->deniedByPath($target->path)) {
            return false;
        }
        if ($target->routeName === null) {
            return !$this->requireRoute;
        }
        $route = $this->router->getRouteCollection()->get($target->routeName);

        return $route instanceof Route && $this->isPageRoute($target->routeName, $route);
    }

    private function deniedByPath(string $path): bool
    {
        foreach ($this->deniedPathPrefixes as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        foreach ($this->deniedPathPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isPageRoute(string $name, Route $route): bool
    {
        if (in_array($name, $this->deniedRoutes, true)) {
            return false;
        }
        foreach ($this->deniedRoutePatterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return false;
            }
        }
        if ($this->routeOption !== null && $route->getOption($this->routeOption) === false) {
            return false;
        }
        $format = $route->getDefault('_format');
        if (is_string($format) && $format !== '' && $format !== 'html') {
            return false;
        }

        return !$this->checkControllerResponse || !$this->controllerReturnsNonPage($route->getDefault('_controller'));
    }

    private function matchGetRoute(string $path): ?string
    {
        // A GET copy of the context: the shared router context is never changed (worker safe).
        $context = (clone $this->router->getContext())->setMethod(Request::METHOD_GET);
        try {
            $match = (new UrlMatcher($this->router->getRouteCollection(), $context))->match($path);
        } catch (RoutingException) {
            return null;
        }
        $name = $match['_route'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function controllerReturnsNonPage(mixed $controller): bool
    {
        if (!is_string($controller) || $controller === '') {
            return false;
        }
        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
        if (!method_exists($class, $method)) {
            return false;
        }
        $type = (new ReflectionMethod($class, $method))->getReturnType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }
        foreach (self::NON_PAGE_RESPONSES as $nonPage) {
            if (is_a($type->getName(), $nonPage, true)) {
                return true;
            }
        }

        return false;
    }
}
