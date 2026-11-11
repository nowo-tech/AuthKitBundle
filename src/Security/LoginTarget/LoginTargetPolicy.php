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
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;

use function explode;
use function in_array;
use function is_a;
use function is_array;
use function is_file;
use function is_string;
use function method_exists;
use function parse_url;
use function preg_match;
use function rawurldecode;
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
 * Stateless: routes are matched with a GET copy of the router's compiled matcher, never by changing the shared one, and
 * the route rules come from a table built at cache warm-up (worker safe, no route collection at request time outside
 * debug).
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
        private ?string $routeTableFile = null,
    ) {
    }

    /**
     * Names of the routes the route rules refuse (denied names and name patterns, the route option, `_format`,
     * controller return types), as a set. Built once by {@see LoginTargetCacheWarmer}; never at request time when the
     * warmed table exists.
     *
     * @return array<string, true>
     */
    public function nonPageRouteNames(RouteCollection $routes): array
    {
        $names = [];
        foreach ($routes->all() as $name => $route) {
            if (!$this->isPageRoute($name, $route)) {
                $names[$name] = true;
            }
        }

        return $names;
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
        $table = $this->routeTable();
        if ($table !== null) {
            return !isset($table[$target->routeName]);
        }
        $route = $this->router->getRouteCollection()->get($target->routeName);

        return $route instanceof Route && $this->isPageRoute($target->routeName, $route);
    }

    /**
     * The warmed non-page route set (opcache keeps the included array), or null when there is none (debug, not warmed).
     *
     * @return array<string, true>|null
     */
    private function routeTable(): ?array
    {
        if ($this->routeTableFile === null || !is_file($this->routeTableFile)) {
            return null;
        }
        $table = require $this->routeTableFile;

        return is_array($table) ? $table : null;
    }

    /**
     * The path is checked raw and percent-decoded once (the router decodes it once before matching: `/%61pi/x` is
     * served by `/api/x`), prefixes case-insensitively. A pattern that fails at run time (PCRE error) denies.
     */
    private function deniedByPath(string $path): bool
    {
        $candidates = [$path];
        $decoded    = rawurldecode($path);
        if ($decoded !== $path) {
            $candidates[] = $decoded;
        }
        foreach ($candidates as $candidate) {
            $lower = strtolower($candidate);
            foreach ($this->deniedPathPrefixes as $prefix) {
                $prefix = strtolower(rtrim($prefix, '/'));
                if ($prefix === '' || $lower === $prefix || str_starts_with($lower, $prefix . '/')) {
                    return true;
                }
            }
            foreach ($this->deniedPathPatterns as $pattern) {
                if (preg_match($pattern, $candidate) !== 0) {
                    return true;
                }
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
            if (preg_match($pattern, $name) !== 0) {
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
        // A GET copy of the context and of the router's compiled matcher: the shared ones are never changed (worker
        // safe), and no route collection is loaded at request time.
        $context = (clone $this->router->getContext())->setMethod(Request::METHOD_GET);
        $matcher = $this->router instanceof Router ? $this->router->getMatcher() : null;
        $matcher = $matcher instanceof UrlMatcherInterface ? $this->copyWithContext($matcher, $context) : new UrlMatcher($this->router->getRouteCollection(), $context);
        try {
            $match = $matcher->match($path);
        } catch (RoutingException) {
            return null;
        }
        $name = $match['_route'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * A copy of the router's compiled matcher with its own context (the router keeps its matcher and context).
     */
    private function copyWithContext(UrlMatcherInterface $shared, RequestContext $context): UrlMatcherInterface
    {
        $copy = clone $shared;
        $copy->setContext($context);

        return $copy;
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
