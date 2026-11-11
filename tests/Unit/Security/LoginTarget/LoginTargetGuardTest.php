<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\LoginTarget;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetFallbackProviderInterface;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetGuard;
use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetPolicy;
use Nowo\AuthKitBundle\Tests\Support\ArrayRouter;
use Nowo\AuthKitBundle\Tests\Support\LoginTargetFixtureController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

use function gc_collect_cycles;
use function memory_get_usage;

final class LoginTargetGuardTest extends TestCase
{
    public function testMachineTargetBecomesTheFallbackAndTheResponseIsKept(): void
    {
        $response = new RedirectResponse('http://example.com/api/notifications/count');
        $response->headers->setCookie(Cookie::create('REMEMBERME', 'x'));
        $request = $this->request('/api/notifications/count');
        $event   = $this->event($request, $response);

        $this->guard()->onLoginSuccess($event);

        self::assertSame($response, $event->getResponse());
        self::assertSame('/home', $response->getTargetUrl());
        self::assertCount(1, $response->headers->getCookies());
        self::assertFalse($request->getSession()->has('_security.main.target_path'), 'A refused saved target is forgotten.');
    }

    public function testPageTargetIsKept(): void
    {
        $request = $this->request('http://example.com/projects/42');
        $event   = $this->event($request, new RedirectResponse('http://example.com/projects/42'));

        $this->guard()->onLoginSuccess($event);

        self::assertSame('http://example.com/projects/42', $event->getResponse()?->headers->get('Location'));
        self::assertSame('http://example.com/projects/42', $request->getSession()->get('_security.main.target_path'));
    }

    public function testPlainResponseWithLocationHeaderAndMissingLocation(): void
    {
        $response = new Response('', 302, ['Location' => '//evil.example']);
        $this->guard()->onLoginSuccess($this->event($this->request(null), $response));
        self::assertSame('/home', $response->headers->get('Location'));

        $noLocation = new Response('', 303);
        $this->guard()->onLoginSuccess($this->event($this->request(null), $noLocation));
        self::assertSame('/home', $noLocation->headers->get('Location'));
    }

    public function testOtherFirewallsNullAndNonRedirectResponsesAreLeftAlone(): void
    {
        $request  = $this->request('/api/x');
        $response = new RedirectResponse('/api/x');
        $this->guard()->onLoginSuccess($this->event($request, $response, 'api'));
        self::assertSame('/api/x', $response->getTargetUrl());
        self::assertSame('/api/x', $request->getSession()->get('_security.main.target_path'), 'Another firewall: nothing touched.');

        $event = $this->event($this->request(null), null);
        $this->guard()->onLoginSuccess($event);
        self::assertNull($event->getResponse());

        $ok = new Response('<p>signed in</p>');
        $this->guard()->onLoginSuccess($this->event($this->request(null), $ok));
        self::assertFalse($ok->headers->has('Location'));
    }

    public function testRequestWithoutSession(): void
    {
        $response = new RedirectResponse('/api/x');
        $this->guard()->onLoginSuccess($this->event(Request::create('http://example.com/login'), $response));

        self::assertSame('/home', $response->getTargetUrl());
    }

    public function testFallbackOrder(): void
    {
        $request  = Request::create('http://example.com/login');
        $provider = new class implements LoginTargetFallbackProviderInterface {
            public function fallback(string $firewall, string $userIdentifier): ?string
            {
                return match ($userIdentifier) {
                    'ada'   => '/start/ada?tab=1',
                    'eve'   => '//evil.example/start',
                    'mal'   => 'https://evil.example/',
                    'empty' => '',
                    default => null,
                };
            }
        };
        $guard = new LoginTargetGuard($this->policy(), $this->router(), ['main', 'admin'], ['main' => 'kernel_home', 'admin' => null], $provider);

        self::assertSame('/start/ada?tab=1', $guard->fallback($request, 'main', 'ada'));
        self::assertSame('/home', $guard->fallback($request, 'main', 'eve'));
        self::assertSame('/home', $guard->fallback($request, 'main', 'mal'));
        self::assertSame('/home', $guard->fallback($request, 'main', 'empty'));
        self::assertSame('/home', $guard->fallback($request, 'main', 'bob'));
        self::assertSame('/', $guard->fallback($request, 'admin', 'bob'));
        self::assertSame('/', $guard->fallback($request, 'other', 'bob'));
    }

    /**
     * FrankenPHP worker mode (kernel kept, no reset): the same guard serves many sign-ins of different users without
     * keeping anything between them and without memory growth.
     */
    public function testUnknownFallbackRouteFallsBackToTheRoot(): void
    {
        $guard = new LoginTargetGuard($this->policy(), $this->router(), ['main'], ['main' => 'no_such_route']);

        self::assertSame('/', $guard->fallback($this->request(null), 'main', 'ada'));
    }

    public function testWorkerLoopKeepsNoStateAndFlatMemory(): void
    {
        $guard    = $this->guard();
        $wrong    = 0;
        $baseline = 0;
        for ($i = 0; $i < 3000; ++$i) {
            $machine  = $i % 2 === 0;
            $target   = $machine ? 'http://example.com/api/poll' : 'http://example.com/projects/' . ($i % 50);
            $response = new RedirectResponse($target);
            $guard->onLoginSuccess($this->event($this->request($target), $response, 'main', 'user' . ($i % 7)));
            $wrong += $response->getTargetUrl() === ($machine ? '/home' : $target) ? 0 : 1;
            if ($i === 999) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertSame(0, $wrong);
        self::assertLessThan(256 * 1024, memory_get_usage() - $baseline);
    }

    private function guard(): LoginTargetGuard
    {
        return new LoginTargetGuard($this->policy(), $this->router(), ['main'], ['main' => 'kernel_home']);
    }

    private function policy(): LoginTargetPolicy
    {
        return new LoginTargetPolicy($this->router(), [], Configuration::DEFAULT_DENIED_PATH_PREFIXES, Configuration::DEFAULT_DENIED_PATH_PATTERNS);
    }

    private function router(): ArrayRouter
    {
        $routes = new RouteCollection();
        $routes->add('kernel_home', new Route('/home', ['_controller' => LoginTargetFixtureController::class . '::page']));
        $routes->add('project_show', new Route('/projects/{id}', ['_controller' => LoginTargetFixtureController::class . '::page']));

        return new ArrayRouter($routes);
    }

    private function request(?string $savedTarget): Request
    {
        $request = Request::create('http://example.com/login', 'POST');
        $session = new Session(new MockArraySessionStorage());
        if ($savedTarget !== null) {
            $session->set('_security.main.target_path', $savedTarget);
        }
        $request->setSession($session);

        return $request;
    }

    private function event(Request $request, ?Response $response, string $firewall = 'main', string $user = 'ada'): LoginSuccessEvent
    {
        $account  = new InMemoryUser($user, null, ['ROLE_USER']);
        $passport = new SelfValidatingPassport(new UserBadge($user, static fn (): InMemoryUser => $account));

        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            $passport,
            new UsernamePasswordToken($account, $firewall, ['ROLE_USER']),
            $request,
            $response,
            $firewall,
        );
    }
}
