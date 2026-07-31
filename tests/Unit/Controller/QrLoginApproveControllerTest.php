<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Controller\QrLoginApproveController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\QrLogin\NullQrLoginStepUp;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\QrLogin\QrLoginRateLimiter;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginUserResolver;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class QrLoginApproveControllerTest extends TestCase
{
    /**
     * @param array<string, mixed> $qr
     *
     * @return array<string, mixed>
     */
    private function overrides(array $qr = []): array
    {
        return [
            'qr_login' => array_merge([
                'mode'                 => 'enabled',
                'approve_rate_limit'   => 5,
                'approve_requires'     => 'session',
                'phone_field'          => 'phone',
                'phone_verified_field' => 'phoneVerifiedAt',
            ], $qr),
        ];
    }

    private function challenge(string $status = 'pending'): QrLoginChallenge
    {
        $challenge = new QrLoginChallenge(
            'approve-id',
            'APPR1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Firefox · Linux',
            hash('sha256', 'good-token'),
            new DateTimeImmutable('+90 seconds'),
        );
        if ($status === 'approved') {
            $challenge->markApproved(TestUser::class, 'u@test.com', '+34 *** 22');
        }

        return $challenge;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function controller(
        QrLoginChallengeManager $manager,
        array $overrides = [],
        ?TokenStorage $tokenStorage = null,
        ?Environment $twig = null,
        ?QrLoginStepUpInterface $stepUp = null,
        ?QrLoginRateLimiter $rateLimiter = null,
    ): QrLoginApproveController {
        $overrides = $overrides !== [] ? $overrides : $this->overrides();

        return new QrLoginApproveController(
            $twig ?? $this->createMock(Environment::class),
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $overrides)),
            $manager,
            new QrLoginUserResolver(
                ProfileRegistryFactory::single(TestUser::class, $overrides),
                PropertyAccess::createPropertyAccessor(),
            ),
            $rateLimiter ?? new QrLoginRateLimiter(new AuthKitAttemptLimiter(new ArrayAdapter())),
            $stepUp ?? new NullQrLoginStepUp(),
            $tokenStorage ?? new TokenStorage(),
            ProfileRegistryFactory::requestResolver(TestUser::class, $overrides),
        );
    }

    private function authenticatedStorage(): TokenStorage
    {
        $user = new TestUser();
        $user->setEmail('phone@test.com');
        $user->setPhone('+34600111222');
        $user->setPhoneVerifiedAt(new DateTimeImmutable());

        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $storage;
    }

    public function testForbiddenWhenDisabled(): void
    {
        $manager  = $this->createMock(QrLoginChallengeManager::class);
        $response = $this->controller($manager, $this->overrides(['mode' => 'disabled']))
            ->approve(Request::create('/approve'), 'approve-id');
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testNotFoundWhenChallengeMissing(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn(null);

        $response = $this->controller($manager)->approve(Request::create('/approve'), 'approve-id');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testGoneWhenExpired(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(true);

        $response = $this->controller($manager)->approve(Request::create('/approve'), 'approve-id');
        self::assertSame(Response::HTTP_GONE, $response->getStatusCode());
    }

    public function testConflictWhenAlreadyResolved(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge('approved'));
        $manager->method('isExpiredOrInvalid')->willReturn(false);

        $response = $this->controller($manager)->approve(Request::create('/approve'), 'approve-id');
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testForbiddenWhenTokenInvalid(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(false);

        $response = $this->controller($manager)->approve(
            Request::create('/approve', 'GET', ['t' => 'bad']),
            'approve-id',
        );
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testTooManyApproveAttempts(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(true);

        $limiter    = new QrLoginRateLimiter(new AuthKitAttemptLimiter(new ArrayAdapter()));
        $overrides  = $this->overrides(['approve_rate_limit' => 1]);
        $controller = $this->controller($manager, $overrides, null, null, null, $limiter);

        $request = Request::create('/approve', 'GET', ['t' => 'good-token']);
        $controller->approve($request, 'approve-id');
        $response = $controller->approve($request, 'approve-id');

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
    }

    public function testUnauthorizedWithoutUser(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(true);

        $response = $this->controller($manager)->approve(
            Request::create('/approve', 'GET', ['t' => 'good-token']),
            'approve-id',
        );
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testForbiddenWhenPhoneNotVerified(): void
    {
        $user = new TestUser();
        $user->setEmail('nop@test.com');
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(true);

        $response = $this->controller($manager, $this->overrides(), $storage)->approve(
            Request::create('/approve', 'GET', ['t' => 'good-token']),
            'approve-id',
        );
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testGetRendersApproveForm(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(true);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<form>approve</form>');

        $response = $this->controller($manager, $this->overrides(), $this->authenticatedStorage(), $twig)
            ->approve(Request::create('/approve', 'GET', ['t' => 'good-token']), 'approve-id');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('<form>approve</form>', $response->getContent());
    }

    public function testPostApprovesChallenge(): void
    {
        $challenge = $this->challenge();
        $manager   = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($challenge);
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(true);
        $manager->expects(self::once())->method('approve');

        $response = $this->controller($manager, $this->overrides(), $this->authenticatedStorage())
            ->approve(Request::create('/approve', 'POST', ['t' => 'good-token']), 'approve-id');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Approved', (string) $response->getContent());
        self::assertSame(QrLoginChallengeStatus::Pending, $challenge->getStatus());
    }

    public function testPostStepUpFailure(): void
    {
        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('isExpiredOrInvalid')->willReturn(false);
        $manager->method('verifyApproveToken')->willReturn(true);

        $stepUp = new class implements QrLoginStepUpInterface {
            public function assertUnlocked(Request $request): void
            {
                throw new AccessDeniedException('locked');
            }
        };

        $response = $this->controller(
            $manager,
            $this->overrides(['approve_requires' => 'session_step_up']),
            $this->authenticatedStorage(),
            null,
            $stepUp,
        )->approve(Request::create('/approve', 'POST', ['t' => 'good-token']), 'approve-id');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }
}
