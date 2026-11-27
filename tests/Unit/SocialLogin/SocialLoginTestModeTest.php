<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Mfa\MfaFirstFactor;
use Nowo\AuthKitBundle\Port\SocialLoginTestGate;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestException;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestStart;
use Nowo\AuthKitBundle\SocialLogin\SocialUserProfile;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function json_encode;
use function parse_str;
use function parse_url;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * 2.7.0 test sign-in: minted by the host through {@see SocialLoginTestStart}, marked server-side with the state.
 */
final class SocialLoginTestModeTest extends TestCase
{
    public function testTheMarkerTravelsWithTheStateOnly(): void
    {
        $store = $this->store($this->request());
        $state = $store->issue('okta', null, null, true);

        self::assertFalse($store->isTestPending('okta', 'forged'));
        self::assertFalse($store->isTestPending('google', $state));
        self::assertFalse($store->isTestPending('okta', ''));
        self::assertTrue($store->isTestPending('okta', $state), 'Reading does not burn the state.');
        self::assertSame(['code_verifier' => null, 'nonce' => null, 'test' => true], $store->consumePending('okta', $state));
        self::assertFalse($store->isTestPending('okta', $state));

        $normal = $store->issue('okta');
        self::assertFalse($store->isTestPending('okta', $normal));
    }

    public function testTheStartRefusesWithoutAHandler(): void
    {
        $start = $this->start([], new SocialLoginCredential());
        self::assertFalse($start->hasHandler());

        try {
            $start->start($this->request(), 'google');
            self::fail('Expected a refusal.');
        } catch (SocialLoginTestException $e) {
            self::assertSame(SocialLoginTestException::REASON_NO_HANDLER, $e->reason);
        }

        self::assertFalse($this->start([new stdClass()], new SocialLoginCredential())->hasHandler(), 'Only the port counts.');
    }

    public function testTheStartRefusesWhenSocialLoginIsOff(): void
    {
        $start = $this->start([$this->createStub(SocialLoginTestHandlerInterface::class)], $this->google(), 'disabled');

        $this->expectExceptionObject(new SocialLoginTestException(SocialLoginTestException::REASON_SOCIAL_DISABLED, 'Social login is not enabled for the profile "default".'));
        $start->start($this->request(), 'google');
    }

    public function testAnOAuth2TestStartCarriesTheMarkerAndTheHint(): void
    {
        $request = $this->request();
        $start   = $this->start([$this->createStub(SocialLoginTestHandlerInterface::class)], $this->google()->setEnabled(false), 'enabled', $request);

        $url = $start->start($request, 'google', 'ada@example.com');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('ada@example.com', $query['login_hint'] ?? null);
        self::assertIsString($query['state'] ?? null);
        self::assertTrue($this->store($request)->isTestPending('google', $query['state']));

        $url = $start->start($request, 'google', 'not an address');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('login_hint', $query);
    }

    public function testTheGateNeverSignsIn(): void
    {
        $user    = AuthUserRef::ofId(TestUser::class, '1', 'ada@example.com');
        $handler = $this->createMock(SocialLoginTestHandlerInterface::class);
        $handler->method('handleFailure')->willReturnOnConsecutiveCalls(SocialCallbackResult::redirect('/admin/sso'), SocialCallbackResult::signIn($user, '/home'), null);
        $handler->method('handle')->willReturnOnConsecutiveCalls(SocialCallbackResult::redirect('/admin/sso/ok'), SocialCallbackResult::signIn($user, '/home'));
        $gate    = new SocialLoginTestGate($handler, ProfileRegistryFactory::single(TestUser::class)->getDefault());
        $request = $this->request();

        self::assertSame($handler, $gate->handler());
        self::assertTrue($gate->supports($request, 'okta'));
        self::assertSame('/admin/sso', $gate->handleFailure($request, 'okta', SocialCallbackFailure::Failed)?->headers->get('Location'));
        self::assertNull($gate->handleFailure($request, 'okta', SocialCallbackFailure::Failed), 'A sign-in answer is ignored.');
        self::assertNull($gate->handleFailure($request, 'okta', SocialCallbackFailure::Failed));

        $context = new SocialCallbackContext(ProfileRegistryFactory::single(TestUser::class)->getDefault(), 'okta', new SocialUserProfile('1', null, null, []), ['access_token' => 't', 'refresh_token' => null, 'expires_in' => null, 'raw' => []]);
        self::assertSame('/admin/sso/ok', $gate->handle($request, $context)->headers->get('Location'));
        $this->expectException(LogicException::class);
        $gate->handle($request, $context);
    }

    public function testATestStateWithoutAHandlerAnyMoreIsBurnedAndRefused(): void
    {
        $request = $this->request();
        $store   = $this->store($request);
        $state   = $store->issue('google', null, null, true);
        $request->query->add(['code' => 'c', 'state' => $state]);

        $response = $this->check($store, $this->google(), [])->check($request, 'google');

        self::assertSame('/login', $response->headers->get('Location'));
        self::assertFalse($store->isTestPending('google', $state));
        self::assertNull($store->consumePending('google', $state));
    }

    public function testASignInMarksTheCredentialMfaRule(): void
    {
        $request = $this->request();
        $store   = $this->store($request);
        $request->query->add(['code' => 'c', 'state' => $store->issue('google')]);
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('login');

        // The rule is marked before the link and the sign-in (the subscriber reads it on the token event).
        $this->check($store, $this->google()->setMfaFirstFactorPolicy('exempt'), [], $security)->check($request, 'google');

        self::assertSame('social', MfaFirstFactor::of($request));
        self::assertSame('exempt', MfaFirstFactor::ruleOf($request));
    }

    private function google(): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider('google')->setEnabled(true)->setClientId('id')->setClientSecret('secret');
    }

    /**
     * @param list<object> $handlers
     */
    private function start(array $handlers, SocialLoginCredential $credential, string $mode = 'enabled', ?Request $request = null): SocialLoginTestStart
    {
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findOneByProvider')->willReturn($credential);
        $inner = $this->createStub(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturn('https://app.test/login/social/google/check');

        return new SocialLoginTestStart(
            ProfileRegistryFactory::requestResolver(TestUser::class, ['social_login' => ['mode' => $mode]]),
            $credentials,
            new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog()),
            $this->store($request ?? $this->request()),
            AuthKitTestUrlGenerator::fromMock($inner),
            null,
            $handlers,
        );
    }

    /**
     * @param list<object> $testHandlers
     */
    private function check(SocialLoginStateStore $store, SocialLoginCredential $credential, array $testHandlers, ?Security $security = null): SocialLoginCheckController
    {
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);
        $inner = $this->createStub(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static fn (string $name): string => $name === 'nowo_auth_kit_login' ? '/login' : 'https://app.test/cb');
        $http = new MockHttpClient(static fn (string $method): MockResponse => new MockResponse($method === 'POST'
            ? json_encode(['access_token' => 'tok'], JSON_THROW_ON_ERROR)
            : json_encode(['sub' => '1', 'email' => 'a@b.c', 'email_verified' => true], JSON_THROW_ON_ERROR)));
        $config = ['social_login' => ['mode' => 'enabled']];

        return new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, $config), $credentials),
            $credentials,
            new OAuth2Client($http, new ProviderEndpointCatalog()),
            $store,
            new SocialAccountLinker(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(SocialLoginAccountRepository::class),
                PropertyAccess::createPropertyAccessor(),
                $this->createStub(UserPasswordHasherInterface::class),
                new MockClock(),
            ),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $config),
            $security ?? $this->createStub(Security::class),
            testHandlers: $testHandlers,
        );
    }

    private function request(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function store(Request $request): SocialLoginStateStore
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new SocialLoginStateStore($stack);
    }
}
