<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\LoginTarget;

use Nowo\AuthKitBundle\Security\LoginTarget\LoginTargetPolicy;
use Nowo\AuthKitBundle\Security\LoginTarget\Reauthentication;
use Nowo\AuthKitBundle\Tests\Support\ArrayRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ReauthenticationTest extends TestCase
{
    public function testTellsARememberedSessionFromAFullSignIn(): void
    {
        $user       = new InMemoryUser('ada', null);
        $remembered = new RememberMeToken($user, 'main');
        $full       = new UsernamePasswordToken($user, 'main');

        self::assertTrue(Reauthentication::isRememberedOnly($remembered));
        self::assertFalse(Reauthentication::isFullySignedIn($remembered));
        self::assertFalse(Reauthentication::isRememberedOnly($full));
        self::assertTrue(Reauthentication::isFullySignedIn($full));
        self::assertFalse(Reauthentication::isRememberedOnly(null));
        self::assertFalse(Reauthentication::isFullySignedIn(null));
        self::assertFalse(Reauthentication::isFullySignedIn(new NullToken()));
    }

    public function testKeepsASameOriginTarget(): void
    {
        $request = $this->request('/login?_target_path=%2Faccount%2Fwebauthn');
        Reauthentication::keepTarget($request, 'main');

        self::assertSame('http://localhost/account/webauthn', $request->getSession()->get('_security.main.target_path'));

        $request = $this->request('/login?_target_path=' . rawurlencode('https://localhost/projects/1?tab=a'), 'https://localhost');
        Reauthentication::keepTarget($request, 'main');
        self::assertSame('https://localhost/projects/1?tab=a', $request->getSession()->get('_security.main.target_path'));
    }

    public function testTheBaseUrlIsNotDoubled(): void
    {
        // Audit ZDD-L1: the passkey page links to /app/login?_target_path=/app/account/webauthn under a base URL.
        $request = Request::create('http://localhost/app/login?_target_path=%2Fapp%2Faccount%2Fwebauthn', server: [
            'SCRIPT_NAME'     => '/app/index.php',
            'SCRIPT_FILENAME' => '/var/www/app/index.php',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame('/app', $request->getBaseUrl());
        Reauthentication::keepTarget($request, 'main');

        self::assertSame('http://localhost/app/account/webauthn', $request->getSession()->get('_security.main.target_path'));
    }

    public function testWithAPolicyOnlyAnAllowedPageIsKept(): void
    {
        // Audit ZDD-I1: a crafted link cannot choose a route the post-login guard refuses.
        $routes = new RouteCollection();
        $routes->add('page', new Route('/projects/{id}', ['_controller' => self::class . '::testKeepsASameOriginTarget']));
        $routes->add('feed', new Route('/feed', ['_controller' => self::class . '::testKeepsASameOriginTarget', '_format' => 'json']));
        $policy = new LoginTargetPolicy(new ArrayRouter($routes), checkControllerResponse: false);

        $request = $this->request('/login?_target_path=%2Ffeed');
        Reauthentication::keepTarget($request, 'main', $policy);
        self::assertNull($request->getSession()->get('_security.main.target_path'));

        $request = $this->request('/login?_target_path=%2Fprojects%2F7');
        Reauthentication::keepTarget($request, 'main', $policy);
        self::assertSame('http://localhost/projects/7', $request->getSession()->get('_security.main.target_path'));
    }

    #[DataProvider('refusedTargets')]
    public function testIgnoresAnyOtherTarget(string $query): void
    {
        $request = $this->request('/login' . $query);
        $request->getSession()->set('_security.main.target_path', 'http://localhost/saved');
        Reauthentication::keepTarget($request, 'main');

        self::assertSame('http://localhost/saved', $request->getSession()->get('_security.main.target_path'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'none' => [''];
        yield 'other origin' => ['?_target_path=https%3A%2F%2Fevil.example%2F'];
        yield 'protocol relative' => ['?_target_path=%2F%2Fevil.example%2F'];
        yield 'relative' => ['?_target_path=account'];
        yield 'array' => ['?_target_path%5B%5D=%2Faccount'];
    }

    public function testWithoutASessionNothingIsSaved(): void
    {
        $request = Request::create('/login?_target_path=%2Faccount');
        Reauthentication::keepTarget($request, 'main');

        self::assertFalse($request->hasSession());
    }

    private function request(string $uri, string $origin = 'http://localhost'): Request
    {
        $request = Request::create($origin . $uri);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
