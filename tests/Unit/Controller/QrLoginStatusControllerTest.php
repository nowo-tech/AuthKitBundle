<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Controller\QrLoginStatusController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class QrLoginStatusControllerTest extends TestCase
{
    public function testReturns404WhenChallengeNotFound(): void
    {
        $challengeManager = $this->createMock(QrLoginChallengeManager::class);
        $challengeManager->method('find')->willReturn(null);

        $controller = new QrLoginStatusController(
            $challengeManager,
            ProfileRegistryFactory::requestResolver(TestUser::class),
        );

        $response = $controller->status(new Request(), 'nonexistent-id');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testReturns403WhenCookieInvalid(): void
    {
        $challenge = new QrLoginChallenge(
            'test-uuid',
            'CODE1234',
            hash('sha256', 'real_cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome',
            hash('sha256', 'token'),
            new DateTimeImmutable('+60 seconds'),
        );

        $challengeManager = $this->createMock(QrLoginChallengeManager::class);
        $challengeManager->method('find')->willReturn($challenge);
        $challengeManager->method('verifyDesktopCookie')->willReturn(false);

        $controller = new QrLoginStatusController(
            $challengeManager,
            ProfileRegistryFactory::requestResolver(TestUser::class),
        );

        $response = $controller->status(new Request(), 'test-uuid');
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testReturnsStatusAndExpiresIn(): void
    {
        $challenge = new QrLoginChallenge(
            'test-uuid-status',
            'CODE5678',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Safari · macOS',
            hash('sha256', 'token'),
            new DateTimeImmutable('+60 seconds'),
        );

        $challengeManager = $this->createMock(QrLoginChallengeManager::class);
        $challengeManager->method('find')->willReturn($challenge);
        $challengeManager->method('verifyDesktopCookie')->willReturn(true);
        $challengeManager->method('isExpiredOrInvalid')->willReturn(false);

        $controller = new QrLoginStatusController(
            $challengeManager,
            ProfileRegistryFactory::requestResolver(TestUser::class),
        );

        $request = Request::create('/status', 'GET', [], [
            QrLoginChallengeManager::DESKTOP_COOKIE_NAME => 'cookie',
        ]);
        $response = $controller->status($request, 'test-uuid-status');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $content = $response->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertSame('pending', $data['status']);
        self::assertArrayHasKey('expires_in', $data);
        self::assertGreaterThan(0, $data['expires_in']);
        self::assertArrayNotHasKey('user_id', $data);
        self::assertArrayNotHasKey('phone', $data);
    }
}
