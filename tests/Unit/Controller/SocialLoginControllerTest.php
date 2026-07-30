<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Controller\SocialLoginStartController;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use const JSON_THROW_ON_ERROR;

final class SocialLoginControllerTest extends TestCase
{
    public function testStartRedirectsWhenDisabled(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);
        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new SocialLoginStartController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'disabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $this->stateStore(new Request()),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class),
        );

        $response = $controller->start(new Request(), 'google');
        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testStartRedirectsWhenCredentialMissing(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([(new SocialLoginCredential())->setEnabled(true)]);
        $credentials->method('findOneByProvider')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new SocialLoginStartController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $this->stateStore(new Request()),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
        );

        self::assertSame('/login', $controller->start(new Request(), 'google')->headers->get('Location'));
    }

    public function testStartRedirectsWhenCredentialDisabled(): void
    {
        $credential  = (new SocialLoginCredential())->setProvider('google')->setEnabled(false);
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([(new SocialLoginCredential())->setEnabled(true)]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new SocialLoginStartController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $this->stateStore(new Request()),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
        );

        self::assertSame('/login', $controller->start(new Request(), 'google')->headers->get('Location'));
    }

    public function testCheckRedirectsWhenDisabled(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([]);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'disabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $this->stateStore(new Request()),
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class),
            $this->createMock(Security::class),
        );

        self::assertSame('/login', $controller->check(new Request(), 'google')->headers->get('Location'));
    }

    public function testStartRedirectsToProvider(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setEnabled(true)
            ->setClientId('id')
            ->setClientSecret('secret');

        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->with('google')->willReturn($credential);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stateStore = $this->stateStore($request);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('https://app.test/callback');

        $controller = new SocialLoginStartController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $stateStore,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
        );

        $response = $controller->start($request, 'google');
        self::assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
        self::assertStringContainsString('client_id=id', (string) $response->headers->get('Location'));
    }

    public function testCheckSuccessLogsIn(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setEnabled(true)
            ->setClientId('id')
            ->setClientSecret('secret');

        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'access_token' => 'tok',
                'expires_in'   => 60,
            ], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'sub'            => '1',
                'email'          => 'a@b.c',
                'email_verified' => true,
                'name'           => 'A',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($userRepo);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::exactly(2))->method('flush');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $linker = new SocialAccountLinker(
            $em,
            $accountRepo,
            PropertyAccess::createPropertyAccessor(),
            $hasher,
            new MockClock(),
        );

        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('login');

        $request = new Request(['code' => 'c', 'state' => 'will-be-replaced']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stateStore = $this->stateStore($request);
        $state      = $stateStore->issue('google');
        $request->query->set('state', $state);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static fn (string $route): string => match ($route) {
            'nowo_auth_kit_login' => '/login',
            'homepage'            => '/home',
            default               => 'https://app.test/callback',
        });

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login'        => ['mode' => 'enabled'],
                'login_success_route' => 'homepage',
            ]), $credentials),
            $credentials,
            new OAuth2Client($http, new ProviderEndpointCatalog()),
            $stateStore,
            $linker,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login'        => ['mode' => 'enabled'],
                'login_success_route' => 'homepage',
            ]),
            $security,
        );

        $response = $controller->check($request, 'google');
        self::assertSame('/home', $response->headers->get('Location'));
    }

    public function testCheckInvalidState(): void
    {
        $credential  = (new SocialLoginCredential())->setProvider('google')->setEnabled(true)->setClientId('id')->setClientSecret('s');
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $request = new Request(['code' => 'c', 'state' => 'bad']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $this->stateStore($request),
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
            $this->createMock(Security::class),
        );

        $response = $controller->check($request, 'google');
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testCheckProviderErrorAndRuntimeFailure(): void
    {
        $credential  = (new SocialLoginCredential())->setProvider('google')->setEnabled(true)->setClientId('id')->setClientSecret('s');
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $http = new MockHttpClient([
            new MockResponse(json_encode(['error' => 'invalid'], JSON_THROW_ON_ERROR)),
        ]);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static fn (string $route): string => match ($route) {
            'nowo_auth_kit_login' => '/login',
            default               => 'https://app.test/callback',
        });

        $denied = new Request(['code' => 'c', 'state' => 's', 'error' => 'access_denied']);
        $denied->setSession(new Session(new MockArraySessionStorage()));
        $deniedStore = $this->stateStore($denied);
        $denied->query->set('state', $deniedStore->issue('google'));

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            new OAuth2Client($http, new ProviderEndpointCatalog()),
            $deniedStore,
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
            $this->createMock(Security::class),
        );

        self::assertSame('/login', $controller->check($denied, 'google')->headers->get('Location'));

        $fail = new Request(['code' => 'c', 'state' => 's']);
        $fail->setSession(new Session(new MockArraySessionStorage()));
        $failStore = $this->stateStore($fail);
        $fail->query->set('state', $failStore->issue('google'));

        $failController = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            new OAuth2Client($http, new ProviderEndpointCatalog()),
            $failStore,
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
            $this->createMock(Security::class),
        );

        self::assertSame('/login', $failController->check($fail, 'google')->headers->get('Location'));
    }

    public function testCheckRedirectsWhenCredentialMissing(): void
    {
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([(new SocialLoginCredential())->setEnabled(true)]);
        $credentials->method('findOneByProvider')->willReturn(null);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $this->stateStore(new Request()),
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
            $this->createMock(Security::class),
        );

        self::assertSame('/login', $controller->check(new Request(), 'google')->headers->get('Location'));
    }

    private function oauthClient(): OAuth2Client
    {
        return new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog());
    }

    private function stateStore(Request $request): SocialLoginStateStore
    {
        if (!$request->hasSession()) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }
        $stack = new RequestStack();
        $stack->push($request);

        return new SocialLoginStateStore($stack);
    }

    private function linker(): SocialAccountLinker
    {
        return new SocialAccountLinker(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(SocialLoginAccountRepository::class),
            PropertyAccess::createPropertyAccessor(),
            $this->createMock(UserPasswordHasherInterface::class),
            new MockClock(),
        );
    }
}
