<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Controller\QrLoginShowController;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\QrLogin\NullQrCodeGenerator;
use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class QrLoginShowControllerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function enabledOverrides(): array
    {
        return [
            'qr_login' => [
                'mode'             => 'enabled',
                'poll_interval_ms' => 1500,
            ],
        ];
    }

    private function challenge(string $id = 'show-id'): QrLoginChallenge
    {
        return new QrLoginChallenge(
            $id,
            'CODE1234',
            hash('sha256', 'cookie'),
            hash_hmac('sha256', '127.0.0.1', 'secret'),
            hash_hmac('sha256', 'UA', 'secret'),
            'Chrome · Windows',
            hash('sha256', 'token'),
            new DateTimeImmutable('+90 seconds'),
        );
    }

    private function requestWithSession(?string $approveToken = 'approve-secret'): Request
    {
        $request = Request::create('/login/qr/show-id');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        if ($approveToken !== null) {
            $session->set('ak_qr_approve_show-id', $approveToken);
        }
        $request->setSession($session);

        return $request;
    }

    public function testRedirectsWhenDisabled(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new QrLoginShowController(
            $this->createMock(Environment::class),
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'qr_login' => ['mode' => 'disabled'],
            ])),
            $this->createMock(QrLoginChallengeManager::class),
            new NullQrCodeGenerator(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'qr_login' => ['mode' => 'disabled'],
            ]),
        );

        $response = $controller->show($this->requestWithSession(), 'show-id');
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testRedirectsWhenChallengeMissing(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn(null);

        $controller = new QrLoginShowController(
            $this->createMock(Environment::class),
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $this->enabledOverrides())),
            $manager,
            new NullQrCodeGenerator(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->enabledOverrides()),
        );

        $response = $controller->show($this->requestWithSession(), 'show-id');
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testRedirectsWhenDesktopCookieInvalid(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyDesktopCookie')->willReturn(false);

        $controller = new QrLoginShowController(
            $this->createMock(Environment::class),
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $this->enabledOverrides())),
            $manager,
            new NullQrCodeGenerator(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->enabledOverrides()),
        );

        $response = $controller->show($this->requestWithSession(), 'show-id');
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testRedirectsWhenExpired(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login/qr');

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('isExpiredOrInvalid')->willReturn(true);

        $controller = new QrLoginShowController(
            $this->createMock(Environment::class),
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $this->enabledOverrides())),
            $manager,
            new NullQrCodeGenerator(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->enabledOverrides()),
        );

        $response = $controller->show($this->requestWithSession(), 'show-id');
        self::assertSame('/login/qr', $response->headers->get('Location'));
    }

    public function testRedirectsWhenApproveTokenMissingFromSession(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login/qr');

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('isExpiredOrInvalid')->willReturn(false);

        $controller = new QrLoginShowController(
            $this->createMock(Environment::class),
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $this->enabledOverrides())),
            $manager,
            new NullQrCodeGenerator(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->enabledOverrides()),
        );

        $response = $controller->show($this->requestWithSession(null), 'show-id');
        self::assertSame('/login/qr', $response->headers->get('Location'));
    }

    public function testRendersShowPageWithApproveUrlContainingToken(): void
    {
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(
            static function (string $route, array $params = []): string {
                if (str_contains($route, 'approve')) {
                    return 'https://example.test/login/qr/' . $params['id'] . '/approve?t=' . $params['t'];
                }

                return '/' . $route;
            },
        );

        $manager = $this->createMock(QrLoginChallengeManager::class);
        $manager->method('find')->willReturn($this->challenge());
        $manager->method('verifyDesktopCookie')->willReturn(true);
        $manager->method('isExpiredOrInvalid')->willReturn(false);

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with(
                '@NowoAuthKitBundle/security/qr_login_show.html.twig',
                self::callback(static function (array $ctx): bool {
                    return $ctx['challenge_id'] === 'show-id'
                        && $ctx['public_code'] === 'CODE1234'
                        && str_contains((string) $ctx['qr_payload'], 't=approve-secret')
                        && $ctx['qr_data_uri'] === null;
                }),
            )
            ->willReturn('<html>qr</html>');

        $controller = new QrLoginShowController(
            $twig,
            new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, $this->enabledOverrides())),
            $manager,
            new NullQrCodeGenerator(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->enabledOverrides()),
        );

        $response = $controller->show($this->requestWithSession(), 'show-id');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('<html>qr</html>', $response->getContent());
    }
}
