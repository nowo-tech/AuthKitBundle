<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Repository\SamlProviderRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\Saml\Controller\SamlLoginController;
use Nowo\AuthKitBundle\Saml\Controller\SamlMetadataController;
use Nowo\AuthKitBundle\Saml\SamlLoginStart;
use Nowo\AuthKitBundle\Saml\SamlPostRelay;
use Nowo\AuthKitBundle\Saml\SamlProfileMapper;
use Nowo\AuthKitBundle\Saml\SamlRequestStore;
use Nowo\AuthKitBundle\Saml\SamlResponseValidator;
use Nowo\AuthKitBundle\Saml\SamlSettingsFactory;
use Nowo\AuthKitBundle\Saml\SamlSpMetadata;
use Nowo\AuthKitBundle\Saml\SamlTestStart;
use Nowo\AuthKitBundle\Saml\SamlUrls;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\Security\ProgrammaticLoginAuthenticator;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialFormPostRelay;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestException;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginTestHandlerInterface;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\RecordingLogger;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlProviders;
use Nowo\AuthKitBundle\Tests\Support\Saml\SamlResponseBuilder;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

use function array_map;

/**
 * 2.10.0: the controller branches the kernel test cannot reach (SAML off for the profile, a firewall AuthKit cannot sign
 * in on, a tagged object that is not a handler, a pending test without a test handler, HTTP exceptions of a handler).
 */
#[CoversClass(SamlLoginController::class)]
#[CoversClass(SamlMetadataController::class)]
#[CoversClass(SamlTestStart::class)]
#[CoversClass(SamlLoginStart::class)]
final class SamlControllersTest extends TestCase
{
    private const ACS = 'https://sp.example.com/login/saml/acme/acs';

    private ArrayAdapter $cache;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->cache  = new ArrayAdapter();
        $this->logger = new RecordingLogger();
    }

    public function testNothingAnswersWhileSamlIsOffForTheProfile(): void
    {
        $controller = $this->controller(saml: false);
        self::assertSame('/login', $controller->start($this->request(), 'acme')->headers->get('Location'));
        self::assertSame('/login', $controller->acs($this->request(), 'acme')->headers->get('Location'));

        $this->expectException(NotFoundHttpException::class);
        $this->metadataController(SamlProviders::acme(), saml: false)->metadata($this->request(), 'acme');
    }

    public function testMetadataTheLibraryRefusesIsNotFound(): void
    {
        $row = SamlProviders::acme();
        (new ReflectionProperty(SamlProvider::class, 'spEntityId'))->setValue($row, '');

        try {
            $this->metadataController($row)->metadata($this->request(), 'acme');
            self::fail('Built.');
        } catch (NotFoundHttpException) {
            self::assertSame(['AuthKit SAML metadata of provider {provider} could not be built.'], $this->logs());
        }
    }

    public function testAStartTheLibraryRefusesFailsCleanly(): void
    {
        $row = SamlProviders::acme();
        (new ReflectionProperty(SamlProvider::class, 'spEntityId'))->setValue($row, '');
        $request = $this->request();

        self::assertSame('/login', $this->controller(row: $row)->start($request, 'acme')->headers->get('Location'));
        self::assertSame(['AuthKit SAML sign-in could not start for provider {provider} ({reason}).'], $this->logs());
        self::assertFalse($request->getSession()->has(SamlRequestStore::SESSION_KEY));
    }

    public function testNoSignInWhereTheFirewallCannotSignIn(): void
    {
        $request  = $this->relayed(...$this->pending());
        $response = $this->controller(authenticator: new ProgrammaticLoginAuthenticator(['other' => null]))->acs($request, 'acme');

        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function testATaggedObjectThatIsNotAHandlerFailsClosed(): void
    {
        $this->expectException(LogicException::class);
        $this->controller(handlers: [new stdClass()])->acs($this->relayed(...$this->pending()), 'acme');
    }

    public function testAPendingTestWithoutATestHandlerIsBurnedAndRefused(): void
    {
        [$request, $relay, $id] = $this->pending(test: true);
        $controller             = $this->controller();

        self::assertSame('/login', $controller->acs($this->relayed($request, $relay, $id), 'acme')->headers->get('Location'));
        self::assertFalse($request->getSession()->has(SamlRequestStore::SESSION_KEY));
    }

    public function testAHandlerHttpExceptionKeepsItsStatusAndANullFailureAnswerFallsBack(): void
    {
        $handler = $this->createMock(SocialCallbackHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new AccessDeniedHttpException('Not a member.'));
        $handler->method('handleFailure')->willReturn(null);

        try {
            $this->controller(handlers: [$handler])->acs($this->relayed(...$this->pending()), 'acme');
            self::fail('No exception.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }

        // A refusal the handler answers with null: the flash and the login page.
        [$request, $relay, $id] = $this->pending();
        $broken                 = $this->relayed($request, $relay, $id, 'response');
        $broken->request->set('SAMLResponse', 'bm90IGEgcmVzcG9uc2U=');
        self::assertSame('/login', $this->controller(handlers: [$handler])->acs($broken, 'acme')->headers->get('Location'));

        $failing = $this->createMock(SocialCallbackHandlerInterface::class);
        $failing->method('supports')->willReturn(true);
        $failing->method('handle')->willThrowException(new RuntimeException('boom'));
        $failing->expects(self::once())->method('handleFailure')->with(self::anything(), 'saml:acme', SocialCallbackFailure::Failed)->willReturn(null);
        self::assertSame('/login', $this->controller(handlers: [$failing])->acs($this->relayed(...$this->pending()), 'acme')->headers->get('Location'));
    }

    public function testATestStartNeedsAHandlerSamlAndAProvider(): void
    {
        $repository = $this->createStub(SamlProviderRepository::class);
        $repository->method('findOneByProvider')->willReturn(null);
        $handler = $this->createStub(SocialLoginTestHandlerInterface::class);

        foreach ([
            SocialLoginTestException::REASON_NO_HANDLER       => new SamlTestStart(ProfileRegistryFactory::requestResolver(TestUser::class, $this->samlProfile(true)), $repository, $this->loginStart()),
            SocialLoginTestException::REASON_SAML_DISABLED    => new SamlTestStart(ProfileRegistryFactory::requestResolver(TestUser::class), $repository, $this->loginStart(), [new stdClass(), $handler]),
            SocialLoginTestException::REASON_UNKNOWN_PROVIDER => new SamlTestStart(ProfileRegistryFactory::requestResolver(TestUser::class, $this->samlProfile(true)), $repository, $this->loginStart(), [$handler]),
        ] as $reason => $tests) {
            try {
                $tests->start($this->request(), 'acme');
                self::fail('Started.');
            } catch (SocialLoginTestException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
    }

    /**
     * @return array{0: Request, 1: string, 2: string} request with its session, RelayState, request id
     */
    private function pending(bool $test = false): array
    {
        $request = $this->request();
        $store   = new SamlRequestStore(new AuthKitAttemptLimiter($this->cache), new MockClock());
        $relay   = SamlRequestStore::newRelayState();
        $store->issue($request->getSession(), 'default', 'acme', '_request-1', $relay, 600, $test);

        return [$request, $relay, '_request-1'];
    }

    private function relayed(Request $request, string $relay, string $requestId, string $sign = 'assertion'): Request
    {
        $builder       = new SamlResponseBuilder($requestId, self::ACS, 'https://sp.example.com/saml/acme/metadata');
        $builder->sign = $sign;
        $request->setMethod('POST');
        $request->request->replace(['SAMLResponse' => $builder->build(), 'RelayState' => $relay, SocialFormPostRelay::MARKER => '1']);

        return $request;
    }

    private function request(): Request
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function samlProfile(bool $enabled): array
    {
        return ['saml' => [
            'mode'             => $enabled ? 'enabled' : 'disabled',
            'clock_skew'       => 60,
            'request_lifetime' => 600,
            'routes'           => ['start' => ['name' => 'saml_start'], 'acs' => ['name' => 'saml_acs'], 'metadata' => ['name' => 'saml_metadata']],
        ]];
    }

    private function urls(): SamlUrls
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => match ($name) {
            'saml_acs' => 'https://sp.example.com/login/saml/' . $parameters['provider'] . '/acs',
            default    => 'https://sp.example.com/saml/' . $parameters['provider'] . '/metadata',
        });

        return new SamlUrls(new AbsoluteUrlGenerator($router));
    }

    private function loginStart(): SamlLoginStart
    {
        return new SamlLoginStart(new SamlSettingsFactory(), new SamlRequestStore(new AuthKitAttemptLimiter($this->cache), new MockClock()), $this->urls());
    }

    /**
     * @return list<string>
     */
    private function logs(): array
    {
        return array_map(static fn (array $record): string => $record[0], $this->logger->records);
    }

    private function metadataController(SamlProvider $row, bool $saml = true): SamlMetadataController
    {
        $repository = $this->createStub(SamlProviderRepository::class);
        $repository->method('findOneByProvider')->willReturn($row);

        return new SamlMetadataController(ProfileRegistryFactory::requestResolver(TestUser::class, $this->samlProfile($saml)), $repository, new SamlSpMetadata(new SamlSettingsFactory(), $this->urls()), $this->logger);
    }

    /**
     * @param list<object> $handlers
     */
    private function controller(bool $saml = true, ?SamlProvider $row = null, ProgrammaticLoginAuthenticator $authenticator = new ProgrammaticLoginAuthenticator(), array $handlers = []): SamlLoginController
    {
        $repository = $this->createStub(SamlProviderRepository::class);
        $repository->method('findOneByProvider')->willReturn($row ?? SamlProviders::acme());
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/login');
        $clock = new MockClock();

        return new SamlLoginController(
            ProfileRegistryFactory::requestResolver(TestUser::class, $this->samlProfile($saml)),
            $repository,
            $this->loginStart(),
            new SamlRequestStore(new AuthKitAttemptLimiter($this->cache), $clock),
            new SamlResponseValidator(new SamlSettingsFactory(), $clock),
            new SamlProfileMapper(),
            $this->urls(),
            new SamlPostRelay($this->createStub(FormFactoryInterface::class), $this->createStub(Environment::class)),
            new SocialAccountLinker(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(SocialLoginAccountRepository::class),
                PropertyAccess::createPropertyAccessor(),
                $this->createStub(UserPasswordHasherInterface::class),
                $clock,
            ),
            AuthKitTestUrlGenerator::fromMock($router),
            $this->createStub(Security::class),
            callbackHandlers: $handlers,
            logger: $this->logger,
            loginAuthenticator: $authenticator,
        );
    }
}
