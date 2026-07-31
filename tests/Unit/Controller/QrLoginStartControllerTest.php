<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Controller\QrLoginStartController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\QrLogin\QrLoginRateLimiter;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class QrLoginStartControllerTest extends TestCase
{
    public function testRedirectsWhenDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $gate = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'qr_login' => ['mode' => 'disabled'],
        ]));

        $challengeManager = $this->createMock(QrLoginChallengeManager::class);
        $rateLimiter      = new QrLoginRateLimiter(new AuthKitAttemptLimiter(new ArrayAdapter()));

        $controller = new QrLoginStartController(
            $gate,
            $challengeManager,
            $rateLimiter,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'qr_login' => ['mode' => 'disabled'],
            ]),
        );

        $response = $controller->start(new Request());
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testReturns429WhenRateLimited(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $profileOverrides = [
            'qr_login' => [
                'mode'               => 'enabled',
                'create_rate_limit'  => 1,
                'create_rate_window' => 600,
                'challenge_ttl'      => 90,
            ],
        ];

        $gate = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $profileOverrides));

        $rateLimiter = new QrLoginRateLimiter(new AuthKitAttemptLimiter(new ArrayAdapter()));

        $challenge = new QrLoginChallenge(
            'rate-limit-uuid-0000-000000000000',
            'RATE1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '10.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome · Windows',
            hash('sha256', 'token'),
            new DateTimeImmutable('+90 seconds'),
        );

        $challengeManager = $this->createMock(QrLoginChallengeManager::class);
        $challengeManager->method('create')->willReturn([
            'challenge'     => $challenge,
            'cookie_value'  => 'test_cookie',
            'approve_token' => 'test_token',
        ]);
        $challengeManager->method('createDesktopCookie')->willReturn(
            Cookie::create('ak_qr_desk')->withValue('test_cookie'),
        );

        $controller = new QrLoginStartController(
            $gate,
            $challengeManager,
            $rateLimiter,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $profileOverrides),
        );

        $request = Request::create('/login/qr', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        $controller->start($request);
        $response = $controller->start($request);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    public function testRedirectsToShowOnSuccessAndStoresApproveToken(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => match (true) {
                str_contains($route, 'show') => '/login/qr/' . $params['id'],
                default                      => '/login',
            },
        );

        $profileOverrides = [
            'qr_login' => [
                'mode'               => 'enabled',
                'challenge_ttl'      => 90,
                'create_rate_limit'  => 5,
                'create_rate_window' => 600,
            ],
        ];

        $gate        = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $profileOverrides));
        $rateLimiter = new QrLoginRateLimiter(new AuthKitAttemptLimiter(new ArrayAdapter()));

        $challenge = new QrLoginChallenge(
            'test-uuid-0000-0000-000000000000',
            'ABCD1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome · Windows',
            hash('sha256', 'token'),
            new DateTimeImmutable('+90 seconds'),
        );

        $challengeManager = $this->createMock(QrLoginChallengeManager::class);
        $challengeManager->method('create')->willReturn([
            'challenge'     => $challenge,
            'cookie_value'  => 'test_cookie_value',
            'approve_token' => 'test_approve_token',
        ]);
        $challengeManager->method('createDesktopCookie')->willReturn(
            Cookie::create('ak_qr_desk')->withValue('test_cookie_value'),
        );

        $controller = new QrLoginStartController(
            $gate,
            $challengeManager,
            $rateLimiter,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $profileOverrides),
        );

        $request = Request::create('/login/qr');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request->setSession($session);

        $response = $controller->start($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login/qr/test-uuid-0000-0000-000000000000', $response->headers->get('Location'));
        self::assertNotSame([], $response->headers->getCookies());
        self::assertSame('test_approve_token', $session->get('ak_qr_approve_test-uuid-0000-0000-000000000000'));
    }
}
