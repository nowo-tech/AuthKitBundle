<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginApprovedEvent;
use Nowo\AuthKitBundle\QrLogin\Event\QrLoginChallengeCreatedEvent;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Repository\QrLoginChallengeRepository;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function strlen;

final class QrLoginChallengeManagerTest extends TestCase
{
    private QrLoginChallengeManager $manager;

    private QrLoginChallengeRepository&MockObject $repository;

    private EventDispatcherInterface&MockObject $dispatcher;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(QrLoginChallengeRepository::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->manager = new QrLoginChallengeManager(
            $this->repository,
            ProfileRegistryFactory::single(TestUser::class, [
                'qr_login' => [
                    'mode'                 => 'enabled',
                    'challenge_ttl'        => 90,
                    'poll_interval_ms'     => 1500,
                    'approve_requires'     => 'session',
                    'desktop_binding'      => 'strict',
                    'phone_field'          => 'phone',
                    'phone_verified_field' => 'phoneVerifiedAt',
                    'create_rate_limit'    => 5,
                    'create_rate_window'   => 600,
                    'approve_rate_limit'   => 5,
                ],
            ]),
            $this->dispatcher,
            'test_kernel_secret_for_hmac',
        );
    }

    public function testCreateReturnsChallengeCookieAndToken(): void
    {
        $this->repository->expects(self::once())->method('save');
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(QrLoginChallengeCreatedEvent::class));

        $request = Request::create('/login/qr', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '192.168.1.1',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/120',
        ]);

        $result = $this->manager->create($request);

        self::assertArrayHasKey('challenge', $result);
        self::assertArrayHasKey('cookie_value', $result);
        self::assertArrayHasKey('approve_token', $result);
        self::assertInstanceOf(QrLoginChallenge::class, $result['challenge']);
        self::assertSame(QrLoginChallengeStatus::Pending, $result['challenge']->getStatus());
        self::assertNotEmpty($result['cookie_value']);
        self::assertNotEmpty($result['approve_token']);
        self::assertSame(36, strlen($result['challenge']->getId()));
        self::assertSame(8, strlen($result['challenge']->getPublicCode()));
    }

    public function testCreateDesktopCookie(): void
    {
        $cookie = $this->manager->createDesktopCookie('some_value', 150);

        self::assertSame(QrLoginChallengeManager::DESKTOP_COOKIE_NAME, $cookie->getName());
        self::assertSame('some_value', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('strict', $cookie->getSameSite());
    }

    public function testVerifyDesktopCookieValid(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $checkRequest = Request::create('/status', 'GET', [], [
            QrLoginChallengeManager::DESKTOP_COOKIE_NAME => $result['cookie_value'],
        ]);

        self::assertTrue($this->manager->verifyDesktopCookie($result['challenge'], $checkRequest));
    }

    public function testVerifyDesktopCookieInvalid(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $checkRequest = Request::create('/status', 'GET', [], [
            QrLoginChallengeManager::DESKTOP_COOKIE_NAME => 'wrong_cookie_value',
        ]);

        self::assertFalse($this->manager->verifyDesktopCookie($result['challenge'], $checkRequest));
    }

    public function testVerifyDesktopCookieMissing(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $checkRequest = Request::create('/status');
        self::assertFalse($this->manager->verifyDesktopCookie($result['challenge'], $checkRequest));
    }

    public function testVerifyDesktopBindingStrict(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '10.0.0.5',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);
        $result = $this->manager->create($request);

        $sameRequest = Request::create('/complete', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '10.0.0.5',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);
        self::assertTrue($this->manager->verifyDesktopBinding($result['challenge'], $sameRequest));

        $diffIpRequest = Request::create('/complete', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '10.0.0.99',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);
        self::assertFalse($this->manager->verifyDesktopBinding($result['challenge'], $diffIpRequest));

        $diffUaRequest = Request::create('/complete', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '10.0.0.5',
            'HTTP_USER_AGENT' => 'DifferentBrowser/2.0',
        ]);
        self::assertFalse($this->manager->verifyDesktopBinding($result['challenge'], $diffUaRequest));
    }

    public function testVerifyApproveTokenValid(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        self::assertTrue($this->manager->verifyApproveToken($result['challenge'], $result['approve_token']));
    }

    public function testVerifyApproveTokenInvalid(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        self::assertFalse($this->manager->verifyApproveToken($result['challenge'], 'totally_wrong_token'));
    }

    public function testVerifyApproveTokenAlreadyUsed(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $result['challenge']->markApproveTokenUsed();

        self::assertFalse($this->manager->verifyApproveToken($result['challenge'], $result['approve_token']));
    }

    public function testApproveMarksChallenge(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $user = new TestUser();
        $user->setEmail('user@test.com');

        $this->dispatcher->expects(self::atLeastOnce())
            ->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof QrLoginChallengeCreatedEvent
                || $event instanceof QrLoginApprovedEvent));

        $this->manager->approve($result['challenge'], $user, '+34 *** 78');

        self::assertSame(QrLoginChallengeStatus::Approved, $result['challenge']->getStatus());
        self::assertNotNull($result['challenge']->getApprovedAt());
        self::assertNotNull($result['challenge']->getApproveTokenUsedAt());
        self::assertSame('+34 *** 78', $result['challenge']->getPhoneHint());
    }

    public function testDenyMarksChallenge(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $this->manager->deny($result['challenge']);

        self::assertSame(QrLoginChallengeStatus::Denied, $result['challenge']->getStatus());
        self::assertNotNull($result['challenge']->getApproveTokenUsedAt());
    }

    public function testConsumeMarksChallenge(): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr');
        $result  = $this->manager->create($request);

        $user = new TestUser();
        $user->setEmail('consumer@test.com');

        $result['challenge']->markApproved(TestUser::class, 'consumer@test.com', null);

        $this->manager->consume($result['challenge'], $user);

        self::assertSame(QrLoginChallengeStatus::Consumed, $result['challenge']->getStatus());
        self::assertNotNull($result['challenge']->getConsumedAt());
    }

    public function testIsExpiredOrInvalidMarksExpiredChallenge(): void
    {
        $challenge = new QrLoginChallenge(
            id: 'test-uuid-expired',
            publicCode: 'ABCD1234',
            desktopCookieHash: hash('sha256', 'cookie'),
            desktopIpHash: hash_hmac('sha256', '127.0.0.1', 'secret'),
            desktopUaHash: hash_hmac('sha256', 'UA', 'secret'),
            desktopUaLabel: 'Chrome · Windows',
            approveTokenHash: hash('sha256', 'token'),
            expiresAt: new DateTimeImmutable('-1 second'),
        );

        $this->repository->expects(self::once())->method('save');

        self::assertTrue($this->manager->isExpiredOrInvalid($challenge));
        self::assertSame(QrLoginChallengeStatus::Expired, $challenge->getStatus());
    }

    public function testIsExpiredOrInvalidReturnsFalseForPending(): void
    {
        $challenge = new QrLoginChallenge(
            id: 'test-uuid-valid',
            publicCode: 'EFGH5678',
            desktopCookieHash: hash('sha256', 'cookie'),
            desktopIpHash: hash_hmac('sha256', '127.0.0.1', 'secret'),
            desktopUaHash: hash_hmac('sha256', 'UA', 'secret'),
            desktopUaLabel: 'Safari · macOS',
            approveTokenHash: hash('sha256', 'token'),
            expiresAt: new DateTimeImmutable('+60 seconds'),
        );

        self::assertFalse($this->manager->isExpiredOrInvalid($challenge));
    }

    public function testCreateWithNamedProfileUsesRegistryLookup(): void
    {
        $this->repository->expects(self::once())->method('save');
        $this->dispatcher->method('dispatch');

        $result = $this->manager->create(Request::create('/login/qr'), 'default');

        self::assertInstanceOf(QrLoginChallenge::class, $result['challenge']);
    }

    public function testFindDelegatesToRepository(): void
    {
        $challenge = new QrLoginChallenge(
            'find-me',
            'FIND1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome',
            hash('sha256', 'token'),
            new DateTimeImmutable('+60 seconds'),
        );
        $this->repository->expects(self::once())->method('find')->with('find-me')->willReturn($challenge);

        self::assertSame($challenge, $this->manager->find('find-me'));
    }

    public function testVerifyDesktopBindingOffAlwaysPasses(): void
    {
        $manager = new QrLoginChallengeManager(
            $this->repository,
            ProfileRegistryFactory::single(TestUser::class, [
                'qr_login' => ['mode' => 'enabled', 'desktop_binding' => 'off'],
            ]),
            $this->dispatcher,
            'test_kernel_secret_for_hmac',
        );
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '10.0.0.5',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);
        $result = $manager->create($request);

        $other = Request::create('/complete', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '1.2.3.4',
            'HTTP_USER_AGENT' => 'Other/9.0',
        ]);
        self::assertTrue($manager->verifyDesktopBinding($result['challenge'], $other));
    }

    public function testVerifyDesktopBindingSoftDoesNotBlockMismatch(): void
    {
        $manager = new QrLoginChallengeManager(
            $this->repository,
            ProfileRegistryFactory::single(TestUser::class, [
                'qr_login' => ['mode' => 'enabled', 'desktop_binding' => 'soft'],
            ]),
            $this->dispatcher,
            'test_kernel_secret_for_hmac',
        );
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '10.0.0.5',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);
        $result = $manager->create($request);

        $other = Request::create('/complete', 'GET', [], [], [], [
            'REMOTE_ADDR'     => '1.2.3.4',
            'HTTP_USER_AGENT' => 'Other/9.0',
        ]);
        self::assertTrue($manager->verifyDesktopBinding($result['challenge'], $other));
    }

    public function testIsExpiredOrInvalidTrueWhenAlreadyExpiredStatus(): void
    {
        $challenge = new QrLoginChallenge(
            'already-expired',
            'EXPI1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome',
            hash('sha256', 'token'),
            new DateTimeImmutable('-10 seconds'),
        );
        $challenge->markExpired();

        self::assertTrue($this->manager->isExpiredOrInvalid($challenge));
    }

    /**
     * @dataProvider provideUserAgents
     */
    public function testBuildUaLabelFromUserAgent(string $ua, string $expectedSubstring): void
    {
        $this->repository->method('save');
        $this->dispatcher->method('dispatch');

        $request = Request::create('/login/qr', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => $ua,
        ]);
        $result = $this->manager->create($request);

        self::assertStringContainsString($expectedSubstring, $result['challenge']->getDesktopUaLabel());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function provideUserAgents(): iterable
    {
        yield 'empty' => ['', 'Unknown browser'];
        yield 'firefox windows' => ['Mozilla/5.0 (Windows NT 10.0; rv:120.0) Gecko/20100101 Firefox/120.0', 'Firefox · Windows'];
        yield 'edge' => ['Mozilla/5.0 Edg/120.0', 'Edge'];
        yield 'chrome linux' => ['Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0', 'Chrome · Linux'];
        yield 'safari mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15', 'Safari · macOS'];
        yield 'android' => ['Mozilla/5.0 (Linux; Android 14) Chrome/120.0', 'Chrome · Android'];
        yield 'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1', 'Safari · iOS'];
        yield 'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Safari/604.1', 'Safari · iOS'];
        yield 'generic' => ['CustomAgent/1.0', 'Browser'];
    }
}
