<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Nowo\AuthKitBundle\Controller\SocialLoginCheckController;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\Port\Internal\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Repository\SocialLoginAccountRepository;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutSession;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginGate;
use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\RecordingLogger;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use Nowo\AuthKitBundle\Tests\Unit\Support\AuthKitTestUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * 2.8.0 (audit ZDC-L2): keeping the RP-initiated logout hint never turns a completed sign-in into an error.
 */
final class RpLogoutHintCallbackTest extends TestCase
{
    public function testAHandlerSignInKeepsTheHint(): void
    {
        [$response, $session, $logger] = $this->signInThroughCallback('ada@example.com');

        self::assertSame('signed in', $response->getContent());
        self::assertSame('ada@example.com', (new RpLogoutSession('test-secret'))->take($session)?->userIdentifier);
        self::assertSame([], $logger->records);
    }

    public function testAnIdentifierThatIsNotUtf8KeepsNoHintAndTheSignInStands(): void
    {
        [$response, $session, $logger] = $this->signInThroughCallback("user\xC3(");

        self::assertSame('signed in', $response->getContent());
        self::assertFalse($session->has(RpLogoutSession::SESSION_KEY));
        self::assertCount(1, $logger->records);
        self::assertSame(['provider' => 'okta', 'exception' => JsonException::class], $logger->records[0][1], 'The class only.');
    }

    /**
     * @return array{Response, Session, RecordingLogger}
     */
    private function signInThroughCallback(string $identifier): array
    {
        $idp            = new FakeIdp();
        $idp->nonce     = 'n-1';
        $idp->discovery = ['end_session_endpoint' => 'https://idp.example.com/logout'];
        $stack          = new OidcStack($idp);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);
        $states = new SocialLoginStateStore($requests);
        $state  = $states->issue('okta', null, 'n-1');
        $request->query->replace(['code' => 'code-1', 'state' => $state]);
        // A previous hint (another sign-in): replaced, or dropped when the new one cannot be kept.
        $session->set(RpLogoutSession::SESSION_KEY, 'previous');

        $credential = (new SocialLoginCredential())->setProvider('okta')->setEnabled(true)->setClientId('client-placeholder')
            ->setClientSecret('secret-placeholder')->setIssuer('https://idp.example.com')->setRpInitiatedLogout(true);
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findEnabledOrdered')->willReturn([$credential]);
        $credentials->method('findOneByProvider')->willReturn($credential);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn($identifier);
        $security = $this->createStub(Security::class);
        $security->method('getToken')->willReturnOnConsecutiveCalls(null, $token);

        $handler = $this->createStub(SocialCallbackHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response('signed in'));

        $inner = $this->createStub(UrlGeneratorInterface::class);
        $inner->method('generate')->willReturnCallback(static fn (string $name): string => $name === 'nowo_auth_kit_login' ? '/login' : 'https://app.example.com/cb');
        $config = ['social_login' => ['mode' => 'enabled']];
        $logger = new RecordingLogger();

        $controller = new SocialLoginCheckController(
            new SocialLoginGate(ProfileRegistryFactory::single(TestUser::class, $config), $credentials),
            $credentials,
            new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog()),
            $states,
            new SocialAccountLinker(
                $this->createStub(EntityManagerInterface::class),
                $this->createStub(SocialLoginAccountRepository::class),
                PropertyAccess::createPropertyAccessor(),
                $this->createStub(UserPasswordHasherInterface::class),
                new MockClock(),
            ),
            AuthKitTestUrlGenerator::fromMock($inner),
            ProfileRegistryFactory::requestResolver(TestUser::class, $config),
            $security,
            callbackHandlers: [$handler],
            logger: $logger,
            oidcClient: $stack->client,
            rpLogout: new RpLogoutSession('test-secret'),
        );

        return [$controller->check($request, 'okta'), $session, $logger];
    }
}
