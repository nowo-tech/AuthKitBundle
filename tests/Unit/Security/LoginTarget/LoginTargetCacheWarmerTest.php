<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\LoginTarget;

use LogicException;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetCacheWarmer;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetPolicy;
use Nowo\AuthKitBundle\Tests\Support\ArrayRouter;
use Nowo\AuthKitBundle\Tests\Support\LoginTargetFixtureController;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function file_put_contents;
use function getmypid;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Audit LT-L2 / LT-I4: the route table built at cache warm-up and the fallback route checks.
 */
final class LoginTargetCacheWarmerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-auth-kit-tests/warmer-' . getmypid();
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/nowo_auth_kit/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/nowo_auth_kit');
        @unlink($this->dir . '/blocker');
        @rmdir($this->dir);
    }

    public function testWritesTheNonPageRouteTableIntoTheBuildDirAndPolicyUsesIt(): void
    {
        $router  = $this->router();
        $warmer  = new LoginTargetCacheWarmer(new LoginTargetPolicy($router), $router, ['home', 'list']);
        $written = $warmer->warmUp('/nonexistent-cache-dir', $this->dir);

        $file = $this->dir . '/' . LoginTargetCacheWarmer::FILE;
        self::assertFalse($warmer->isOptional());
        self::assertSame([$file], $written);
        self::assertSame(['feed' => true], require $file);

        $policy = new LoginTargetPolicy($router, routeTableFile: $file);
        self::assertTrue($policy->allows('/', Request::create('http://example.com/login'), 'main'));
        self::assertFalse($policy->allows('/feed', Request::create('http://example.com/login'), 'main'));

        self::assertSame([$file], $warmer->warmUp($this->dir), 'Without a build dir: the cache dir (directory already there).');
    }

    public function testMissingFallbackRouteFailsTheWarmUp(): void
    {
        $router = $this->router();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"nope" does not exist');
        (new LoginTargetCacheWarmer(new LoginTargetPolicy($router), $router, ['nope']))->warmUp($this->dir);
    }

    public function testFallbackRouteWithParametersFailsTheWarmUp(): void
    {
        $router = $this->router();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"show" needs the parameters "id"');
        (new LoginTargetCacheWarmer(new LoginTargetPolicy($router), $router, ['show']))->warmUp($this->dir);
    }

    public function testUnwritableDirectoryFails(): void
    {
        file_put_contents($this->dir . '/blocker', 'x');
        $router = $this->router();
        $this->expectException(RuntimeException::class);
        (new LoginTargetCacheWarmer(new LoginTargetPolicy($router), $router))->warmUp($this->dir . '/blocker');
    }

    public function testNothingIsLeftBehindOnSuccess(): void
    {
        $router = $this->router();
        (new LoginTargetCacheWarmer(new LoginTargetPolicy($router), $router))->warmUp($this->dir);

        self::assertTrue(is_dir($this->dir . '/nowo_auth_kit'));
        self::assertCount(1, glob($this->dir . '/nowo_auth_kit/*') ?: []);
        self::assertTrue(is_file($this->dir . '/' . LoginTargetCacheWarmer::FILE));
    }

    private function router(): ArrayRouter
    {
        $c      = LoginTargetFixtureController::class;
        $routes = new RouteCollection();
        $routes->add('home', new Route('/', ['_controller' => $c . '::page']));
        $routes->add('list', new Route('/list/{page}', ['_controller' => $c . '::page', 'page' => 1]));
        $routes->add('show', new Route('/show/{id}', ['_controller' => $c . '::page']));
        $routes->add('feed', new Route('/feed', ['_controller' => $c . '::json']));

        return new ArrayRouter($routes);
    }
}
