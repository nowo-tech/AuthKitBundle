<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security\LoginTarget;

use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function array_diff;
use function array_keys;
use function array_merge;
use function dirname;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function rename;
use function sprintf;
use function uniqid;
use function var_export;

/**
 * Builds the post-login target route table once per cache build (1.44, audit LT-L2): the names of the routes the route
 * rules refuse, so {@see LoginTargetPolicy} never loads the route collection at request time. Also checks that every
 * configured fallback route exists and needs no parameters (audit LT-I4), so a wrong `fallback_route` fails the
 * deployment instead of a sign-in.
 */
final readonly class LoginTargetCacheWarmer implements CacheWarmerInterface
{
    public const FILE = 'nowo_auth_kit/login_target_routes.php';

    /**
     * @param list<string> $fallbackRoutes
     */
    public function __construct(
        private LoginTargetPolicy $policy,
        private RouterInterface $router,
        private array $fallbackRoutes = [],
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $routes = $this->router->getRouteCollection();
        foreach ($this->fallbackRoutes as $name) {
            $route = $routes->get($name);
            if (!$route instanceof Route) {
                throw new LogicException(sprintf('nowo_auth_kit.login_target: the fallback route "%s" does not exist.', $name));
            }
            $compiled = $route->compile();
            $missing  = array_diff(array_merge($compiled->getPathVariables(), $compiled->getHostVariables()), array_keys($route->getDefaults()));
            if ($missing !== []) {
                throw new LogicException(sprintf('nowo_auth_kit.login_target: the fallback route "%s" needs the parameters "%s".', $name, implode('", "', $missing)));
            }
        }

        $file = ($buildDir ?? $cacheDir) . '/' . self::FILE;
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        // Written atomically (temporary file + rename): a worker never includes a half-written table.
        $tmp = $file . '.' . uniqid('', true);
        if (@file_put_contents($tmp, '<?php return ' . var_export($this->policy->nonPageRouteNames($routes), true) . ";\n") === false || !@rename($tmp, $file)) {
            throw new RuntimeException(sprintf('Cannot write "%s".', $file));
        }

        return [$file];
    }
}
