<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Controller\QrLoginDenyController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class QrLoginDenyControllerTest extends TestCase
{
    private function challenge(bool $approved = false): QrLoginChallenge
    {
        $challenge = new QrLoginChallenge(
            'deny-id',
            'DENY1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Safari · macOS',
            hash('sha256', 'token'),
            new DateTimeImmutable('+90 seconds'),
        );
        if ($approved) {
            $challenge->markApproved(TestUser::class, 'u@test.com', null);
        }

        return $challenge;
    }

    /**
     * @param array<string, mixed> $qr
     */
    private function controller(QrLoginChallengeManager $manager, array $qr = [], ?TokenStorage $storage = null): QrLoginDenyController
    {
        $overrides = ['qr_login' => array_merge(['mode' => 'enabled'], $qr)];

        return new QrLoginDenyController(
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $overrides)),
            $manager,
            $storage ?? new TokenStorage(),
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
        );
    }

    public function testForbiddenWhenDisabled(): void
    {
        $response = $this->controller($this->createMock(QrLoginChallengeManager::class), ['mode' => 'disabled'])
            ->deny(new Request(), 'deny-id');
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testNotFound(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn(null);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->controller($manager)->deny(new Request(), 'deny-id')->getStatusCode());
    }

    public function testConflictWhenResolved(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge(true));
        self::assertSame(Response::HTTP_CONFLICT, $this->controller($manager)->deny(new Request(), 'deny-id')->getStatusCode());
    }

    public function testForbiddenInvalidToken(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyApproveToken')->willReturn(false);

        $response = $this->controller($manager)->deny(Request::create('/deny', 'POST', ['t' => 'bad']), 'deny-id');
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testUnauthorizedWithoutUser(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyApproveToken')->willReturn(true);

        $response = $this->controller($manager)->deny(Request::create('/deny', 'POST', ['t' => 'ok']), 'deny-id');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testDeniesChallenge(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyApproveToken')->willReturn(true);
        $manager->expects(self::once())->method('deny');

        $user = new TestUser();
        $user->setEmail('deny@test.com');
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $response = $this->controller($manager, [], $storage)
            ->deny(Request::create('/deny', 'POST', ['t' => 'ok']), 'deny-id');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('denied', strtolower((string) $response->getContent()));
    }
}
