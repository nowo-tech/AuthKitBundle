<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\LoginTarget;

use ArrayObject;
use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTarget;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetPolicy;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetVoterInterface;
use Nowo\AuthKitBundle\Tests\Support\ArrayRouter;
use Nowo\AuthKitBundle\Tests\Support\LoginTargetFixtureController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class LoginTargetPolicyTest extends TestCase
{
    private const CONTROLLER = LoginTargetFixtureController::class;

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function defaultTargets(): iterable
    {
        yield 'path' => ['/projects/42', true];
        yield 'path with query' => ['/projects/42?tab=time', true];
        yield 'path with empty query' => ['/projects/42?', true];
        yield 'same origin absolute' => ['http://example.com/projects/42', true];
        yield 'same origin, upper case' => ['HTTP://EXAMPLE.COM/projects/42', true];
        yield 'origin without path' => ['http://example.com', true];
        yield 'root' => ['/', true];
        yield 'empty' => ['', false];
        yield 'protocol relative' => ['//evil.example/projects/42', false];
        yield 'backslash' => ['/\\evil.example/projects/42', false];
        yield 'encoded control' => ["/projects/42\n", false];
        yield 'space' => ['/projects/4 2', false];
        yield 'relative' => ['projects/42', false];
        yield 'other host' => ['http://evil.example/projects/42', false];
        yield 'other scheme' => ['https://example.com/projects/42', false];
        yield 'other port' => ['http://example.com:8080/projects/42', false];
        yield 'user info' => ['http://user@example.com/projects/42', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'scheme without host' => ['http:/projects/42', false];
        yield 'unparseable' => ['http://:80', false];
        yield 'double slash path' => ['http://example.com//evil.example', false];
        yield 'api prefix' => ['/api/notifications/count', false];
        yield 'api segment' => ['/chat/api/rooms', false];
        yield 'locale api' => ['/en/api/rooms', false];
        yield 'underscore segment' => ['/_wdt/abc', false];
        yield 'mercure' => ['/.well-known/mercure', false];
        yield 'health' => ['/health/ready', false];
        yield 'health exact' => ['/health', false];
        yield 'healthy page' => ['/healthy', true];
        yield 'json file' => ['/exports/report.json', false];
        yield 'logout path' => ['/logout', false];
        yield 'unknown route' => ['/nowhere', false];
        yield 'post only route' => ['/projects/42/save', false];
        yield 'html false option' => ['/reports/export', false];
        yield 'json format' => ['/projects/42/feed', false];
        yield 'html format' => ['/projects/42/print', true];
        yield 'json controller' => ['/chat/rooms', false];
        yield 'streamed controller' => ['/events', false];
        yield 'file controller' => ['/download', false];
        yield 'untyped controller' => ['/untyped', true];
        yield 'invokable controller' => ['/invokable', true];
        yield 'missing controller method' => ['/ghost-method', true];
        yield 'closure controller' => ['/closure', true];
        yield 'logout route name' => ['/sign-out', false];
        // Open-redirect payloads (REQ-SEC): every one stays on this origin or is refused.
        yield 'protocol relative, bare' => ['//evil', false];
        yield 'backslash, bare' => ['/\\evil', false];
        yield 'double backslash' => ['\\\\evil.example', false];
        yield 'tab before slashes' => ["/\t/evil.example", false];
        yield 'newline before slashes' => ["/\n/evil.example", false];
        yield 'raw CRLF header injection' => ["/projects/42\r\nSet-Cookie: x=1", false];
        // Percent-encoded payloads are opaque to the browser: they stay a path of this origin (no header split).
        yield 'encoded CRLF stays a local path' => ['/projects/42%0d%0aSet-Cookie:%20x=1', true];
        yield 'encoded slashes stay a local path' => ['/%2F%2Fevil.example', false];
        yield 'encoded slashes, bare' => ['%2F%2Fevil.example', false];
        yield 'encoded backslash stays a local path' => ['/%5Cevil.example', false];
        yield 'scheme without slashes' => ['https:evil.example', false];
        yield 'scheme with one slash' => ['https:/evil.example', false];
        yield 'javascript, upper case' => ['JaVaScRiPt:alert(1)', false];
        yield 'data url' => ['data:text/html,<script>alert(1)</script>', false];
        yield 'vbscript' => ['vbscript:msgbox(1)', false];
        yield 'user info lookalike' => ['http://example.com@evil.example/', false];
        yield 'user info with password' => ['http://example.com:x@evil.example/', false];
        yield 'host suffix' => ['http://example.com.evil.example/projects/42', false];
        yield 'null byte' => ["/projects/42\0", false];
        yield 'leading space' => [' /projects/42', false];
        yield 'dot segments to protocol relative' => ['http://example.com/..//evil.example', false];
    }

    #[DataProvider('defaultTargets')]
    public function testDefaultRules(string $target, bool $allowed): void
    {
        self::assertSame($allowed, $this->policy()->allows($target, Request::create('http://example.com/login'), 'main'));
    }

    public function testBaseUrlIsRemovedBeforeMatching(): void
    {
        $request = Request::create('http://example.com/app/index.php/login', 'GET', [], [], [], [
            'SCRIPT_FILENAME' => '/var/www/app/index.php',
            'SCRIPT_NAME'     => '/app/index.php',
            'PHP_SELF'        => '/app/index.php/login',
        ]);
        self::assertSame('/app/index.php', $request->getBaseUrl());
        self::assertSame(['/projects/42', 'a=1'], LoginTargetPolicy::sameOriginParts('/app/index.php/projects/42?a=1', $request));
        self::assertSame(['/', null], LoginTargetPolicy::sameOriginParts('/app/index.php', $request));
        self::assertSame(['/other/x', null], LoginTargetPolicy::sameOriginParts('/other/x', $request));
        self::assertTrue($this->policy()->allows('http://example.com/app/index.php/projects/42', $request, 'main'));
    }

    /**
     * @return iterable<string, array{0: string, 1: array{0: string, 1: string|null}}>
     */
    public static function encodedLocalPaths(): iterable
    {
        yield 'encoded slashes' => ['/%2F%2Fevil.example', ['/%2F%2Fevil.example', null]];
        yield 'encoded backslash' => ['/%5Cevil.example', ['/%5Cevil.example', null]];
        yield 'encoded CRLF' => ['/a%0d%0aSet-Cookie:x', ['/a%0d%0aSet-Cookie:x', null]];
        yield 'encoded scheme in query' => ['/a?next=https%3A%2F%2Fevil.example', ['/a', 'next=https%3A%2F%2Fevil.example']];
    }

    /**
     * @param array{0: string, 1: string|null} $parts
     */
    #[DataProvider('encodedLocalPaths')]
    public function testEncodedPayloadsAreKeptAsLocalPaths(string $target, array $parts): void
    {
        self::assertSame($parts, LoginTargetPolicy::sameOriginParts($target, Request::create('http://example.com/login')));
    }

    public function testPortOfTheRequestIsPartOfTheOrigin(): void
    {
        self::assertTrue($this->policy()->allows('http://example.com:8080/projects/42', Request::create('http://example.com:8080/login'), 'main'));
    }

    public function testRouterContextMethodIsRestored(): void
    {
        $router = $this->router();
        $router->getContext()->setMethod('POST');
        (new LoginTargetPolicy($router))->allows('/projects/42', Request::create('http://example.com/login'), 'main');
        (new LoginTargetPolicy($router))->allows('/nowhere', Request::create('http://example.com/login'), 'main');

        self::assertSame('POST', $router->getContext()->getMethod());
    }

    public function testRulesCanBeRelaxed(): void
    {
        $policy  = new LoginTargetPolicy($this->router(), [], [], [], [], [], null, false, false);
        $request = Request::create('http://example.com/login');

        self::assertTrue($policy->allows('/nowhere', $request, 'main'));
        self::assertTrue($policy->allows('/reports/export', $request, 'main'));
        self::assertTrue($policy->allows('/chat/rooms', $request, 'main'));
        self::assertFalse($policy->allows('//evil.example', $request, 'main'), 'Same-origin checks are never relaxed.');
    }

    public function testDeniedRoutesAndEmptyPrefix(): void
    {
        $request = Request::create('http://example.com/login');

        self::assertFalse((new LoginTargetPolicy($this->router(), [], [], [], ['project_show']))->allows('/projects/42', $request, 'main'));
        self::assertFalse((new LoginTargetPolicy($this->router(), [], ['/']))->allows('/projects/42', $request, 'main'));
    }

    public function testVotersDecideBeforeTheRulesInOrder(): void
    {
        /** @var ArrayObject<int, LoginTarget> $seen */
        $seen    = new ArrayObject();
        $abstain = new class($seen) implements LoginTargetVoterInterface {
            /**
             * @param ArrayObject<int, LoginTarget> $seen
             */
            public function __construct(private readonly ArrayObject $seen)
            {
            }

            public function vote(LoginTarget $target): ?bool
            {
                $this->seen->append($target);

                return null;
            }
        };
        $allowDocs = new class implements LoginTargetVoterInterface {
            public function vote(LoginTarget $target): ?bool
            {
                return match ($target->path) {
                    '/docs/api'    => true,
                    '/projects/42' => false,
                    default        => null,
                };
            }
        };
        $policy  = new LoginTargetPolicy($this->router(), [$abstain, $allowDocs], [], Configuration::DEFAULT_DENIED_PATH_PATTERNS);
        $request = Request::create('http://example.com/login');

        self::assertTrue($policy->allows('/docs/api?x=1', $request, 'admin'));
        self::assertFalse($policy->allows('/projects/42', $request, 'admin'));
        self::assertTrue($policy->allows('/projects/43', $request, 'admin'));
        self::assertFalse($policy->allows('//evil.example/docs/api', $request, 'admin'), 'Voters never see a foreign target.');

        self::assertCount(3, $seen);
        $first  = $seen[0];
        $second = $seen[1];
        self::assertInstanceOf(LoginTarget::class, $first);
        self::assertInstanceOf(LoginTarget::class, $second);
        self::assertSame('/docs/api', $first->path);
        self::assertSame('x=1', $first->query);
        self::assertSame('docs_api', $first->routeName);
        self::assertSame('admin', $first->firewall);
        self::assertNull($second->query);
    }

    public function testMatchWithoutAKnownRoute(): void
    {
        $noName = $this->createStub(RouterInterface::class);
        $noName->method('getContext')->willReturn(new RequestContext());
        $noName->method('match')->willReturn(['_controller' => 'x']);
        $noName->method('getRouteCollection')->willReturn(new RouteCollection());
        self::assertFalse((new LoginTargetPolicy($noName))->allows('/x', Request::create('http://example.com/login'), 'main'));

        $ghost = $this->createStub(RouterInterface::class);
        $ghost->method('getContext')->willReturn(new RequestContext());
        $ghost->method('match')->willReturn(['_route' => 'ghost']);
        $ghost->method('getRouteCollection')->willReturn(new RouteCollection());
        self::assertFalse((new LoginTargetPolicy($ghost))->allows('/x', Request::create('http://example.com/login'), 'main'));
    }

    private function policy(): LoginTargetPolicy
    {
        return new LoginTargetPolicy(
            $this->router(),
            [],
            Configuration::DEFAULT_DENIED_PATH_PREFIXES,
            Configuration::DEFAULT_DENIED_PATH_PATTERNS,
            [],
            Configuration::DEFAULT_DENIED_ROUTE_PATTERNS,
        );
    }

    private function router(): ArrayRouter
    {
        $c      = self::CONTROLLER;
        $routes = new RouteCollection();
        $add    = static function (string $name, string $path, string|callable $controller, array $defaults = [], array $options = [], array $methods = []) use ($routes): void {
            $routes->add($name, new Route($path, ['_controller' => $controller] + $defaults, [], $options, '', [], $methods));
        };
        $add('home', '/', $c . '::page');
        $add('project_show', '/projects/{id}', $c . '::page');
        $add('project_save', '/projects/{id}/save', $c . '::page', [], [], ['POST']);
        $add('project_feed', '/projects/{id}/feed', $c . '::page', ['_format' => 'json']);
        $add('project_print', '/projects/{id}/print', $c . '::page', ['_format' => 'html']);
        $add('report_export', '/reports/export', $c . '::page', [], ['html' => false]);
        $add('chat_rooms', '/chat/rooms', $c . '::json');
        $add('events', '/events', $c . '::stream');
        $add('download', '/download', $c . '::file');
        $add('untyped', '/untyped', $c . '::untyped');
        $add('invokable', '/invokable', $c);
        $add('ghost_method', '/ghost-method', $c . '::nope');
        $add('closure', '/closure', static fn (): string => 'x');
        $add('healthy', '/healthy', $c . '::page');
        $add('app_logout', '/sign-out', $c . '::page');
        $add('docs_api', '/docs/api', $c . '::page');
        $add('api_rooms', '/api/notifications/count', $c . '::json');

        return new ArrayRouter($routes);
    }
}
