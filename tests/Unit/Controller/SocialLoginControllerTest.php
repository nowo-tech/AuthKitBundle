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
use Nowo\AuthKitBundle\SocialLogin\Pkce;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

use function is_string;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

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

    public function testCheckProviderDeniedWithoutCodeShowsProviderDeniedAndBurnsState(): void
    {
        $credential  = (new SocialLoginCredential())->setProvider('google')->setEnabled(true)->setClientId('id')->setClientSecret('s');
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('/login');

        // Real provider denial: error + state, no code (RFC 6749 §4.1.2.1)
        $request = new Request(['error' => 'access_denied']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $stateStore = $this->stateStore($request);
        $state      = $stateStore->issue('google');
        $request->query->set('state', $state);

        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('login');

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            $this->oauthClient(),
            $stateStore,
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
            $security,
        );

        self::assertSame('/login', $controller->check($request, 'google')->headers->get('Location'));
        self::assertSame(['social.error.provider_denied'], $session->getFlashBag()->get('error'));
        self::assertFalse($stateStore->consume('google', $state), 'Denied callback must burn the pending state.');

        // Denial without state still reports provider_denied
        $noState        = new Request(['error' => 'access_denied']);
        $noStateSession = new Session(new MockArraySessionStorage());
        $noState->setSession($noStateSession);
        self::assertSame('/login', $controller->check($noState, 'google')->headers->get('Location'));
        self::assertSame(['social.error.provider_denied'], $noStateSession->getFlashBag()->get('error'));
    }

    public function testCheckSuccessFallsBackToLoginRouteWithoutLoginSuccessRoute(): void
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
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 60], JSON_THROW_ON_ERROR)),
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

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $accountRepo = $this->createMock(SocialLoginAccountRepository::class);
        $accountRepo->method('findOneByProviderSubject')->willReturn(null);

        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('login');

        $request = new Request(['code' => 'c']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stateStore = $this->stateStore($request);
        $request->query->set('state', $stateStore->issue('google'));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static fn (string $route): string => match ($route) {
            'nowo_auth_kit_login' => '/login',
            'homepage'            => throw new RuntimeException('Route "homepage" does not exist.'),
            default               => 'https://app.test/callback',
        });

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]), $credentials),
            $credentials,
            new OAuth2Client($http, new ProviderEndpointCatalog()),
            $stateStore,
            new SocialAccountLinker($em, $accountRepo, PropertyAccess::createPropertyAccessor(), $hasher, new MockClock()),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, [
                'social_login' => ['mode' => 'enabled'],
            ]),
            $security,
        );

        self::assertSame('/login', $controller->check($request, 'google')->headers->get('Location'));
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

    public function testStartSendsPkceChallengeAndKeepsVerifierInSession(): void
    {
        [$controller, $request, $stateStore] = $this->pkceStartController([]);

        $location = (string) $controller->start($request, 'google')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertIsString($query['code_challenge'] ?? null);
        self::assertIsString($query['state'] ?? null);

        $pending = $stateStore->consumePending('google', $query['state']);
        self::assertNotNull($pending);
        self::assertIsString($pending['code_verifier']);
        self::assertSame(Pkce::challenge($pending['code_verifier']), $query['code_challenge']);
    }

    public function testStartOmitsPkceWhenDisabledForProvider(): void
    {
        [$controller, $request, $stateStore] = $this->pkceStartController(['google' => false]);

        $location = (string) $controller->start($request, 'google')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        self::assertArrayNotHasKey('code_challenge', $query);
        self::assertArrayNotHasKey('code_challenge_method', $query);
        self::assertSame(['code_verifier' => null], $stateStore->consumePending('google', is_string($query['state'] ?? null) ? $query['state'] : ''));
    }

    public function testCheckSendsStoredCodeVerifierToTokenEndpoint(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setEnabled(true)
            ->setClientId('id')
            ->setClientSecret('secret');
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $tokenBody = null;
        $http      = new MockHttpClient(static function (string $method, string $url, array $options) use (&$tokenBody): MockResponse {
            if ($method === 'POST') {
                $tokenBody = $options['body'];

                return new MockResponse(json_encode(['access_token' => 'tok'], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['sub' => '1', 'email' => 'a@b.c', 'email_verified' => true], JSON_THROW_ON_ERROR));
        });

        $request = new Request(['code' => 'c']);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stateStore = $this->stateStore($request);
        $request->query->set('state', $stateStore->issue('google', 'stored-verifier'));

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('https://app.test/callback');

        $profileConfig = ['social_login' => ['mode' => 'enabled']];
        $controller    = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, $profileConfig), $credentials),
            $credentials,
            new OAuth2Client($http, new ProviderEndpointCatalog()),
            $stateStore,
            $this->linker(),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $profileConfig),
            $this->createMock(Security::class),
        );

        $controller->check($request, 'google');

        self::assertIsString($tokenBody);
        parse_str($tokenBody, $body);
        self::assertSame('stored-verifier', $body['code_verifier'] ?? null);
    }

    /**
     * @param array<string, bool> $pkce
     *
     * @return array{0: SocialLoginStartController, 1: Request, 2: SocialLoginStateStore}
     */
    private function pkceStartController(array $pkce): array
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setEnabled(true)
            ->setClientId('id')
            ->setClientSecret('secret');
        $credentials = $this->createMock(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stateStore = $this->stateStore($request);

        $inner = $this->createMock(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('https://app.test/callback');

        $profileConfig = ['social_login' => ['mode' => 'enabled', 'pkce' => $pkce]];
        $controller    = new SocialLoginStartController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, $profileConfig), $credentials),
            $credentials,
            $this->oauthClient(),
            $stateStore,
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $profileConfig),
        );

        return [$controller, $request, $stateStore];
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
