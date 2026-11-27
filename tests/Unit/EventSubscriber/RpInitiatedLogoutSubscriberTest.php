<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\EventSubscriber;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\EventSubscriber\RpInitiatedLogoutSubscriber;
use Nowo\AuthKitBundle\Repository\SocialLoginCredentialRepository;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutHint;
use Nowo\AuthKitBundle\SocialLogin\Oidc\RpLogoutSession;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\RecordingLogger;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function parse_str;
use function parse_url;
use function str_starts_with;

use const PHP_URL_QUERY;

/**
 * 2.8.0: OpenID Connect RP-initiated logout on sign-out (deny by default, fail closed, local sign-out first).
 */
final class RpInitiatedLogoutSubscriberTest extends TestCase
{
    private RpLogoutSession $hints;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->hints  = new RpLogoutSession('test-secret');
        $this->logger = new RecordingLogger();
    }

    public function testItRunsAfterTheDefaultTargetAndBeforeTheSessionIsInvalidated(): void
    {
        self::assertSame([LogoutEvent::class => ['onLogout', 16]], RpInitiatedLogoutSubscriber::getSubscribedEvents());
    }

    public function testTheSignOutGoesToTheProviderWithTheHintClientAndAllowedRedirect(): void
    {
        $event = $this->event($this->hint());
        $this->subscriber($this->credential(), ['/signed-out', 'https://app.example.com/'])->onLogout($event);

        $location = $this->location($event);
        self::assertTrue(str_starts_with($location, 'https://idp.example.com/logout?'), $location);
        self::assertSame([
            'id_token_hint'            => 'header.payload.signature',
            'client_id'                => 'client-placeholder',
            'post_logout_redirect_uri' => 'https://app.example.com/',
        ], $this->query($location));
        self::assertFalse($event->getRequest()->getSession()->has(RpLogoutSession::SESSION_KEY), 'The hint is gone with the sign-out.');
    }

    public function testWithoutIdTokenOrAllowListOnlyTheClientIdIsSentAndAQueryIsKept(): void
    {
        $event = $this->event(new RpLogoutHint('okta', 'client-placeholder', 'ada@example.com', 'https://idp.example.com/logout?p=1', null), null);
        $this->subscriber($this->credential(), [])->onLogout($event);

        $location = $this->location($event);
        self::assertTrue(str_starts_with($location, 'https://idp.example.com/logout?p=1&'), $location);
        self::assertSame(['p' => '1', 'client_id' => 'client-placeholder'], $this->query($location));
    }

    public function testNoHintKeepsTheLocalSignOut(): void
    {
        $event = $this->event(null);
        $this->subscriber($this->credential(), ['/'])->onLogout($event);
        self::assertSame('https://app.example.com/', $this->location($event));
    }

    public function testNoSessionCookieNeverStartsASession(): void
    {
        $request = Request::create('https://app.example.com/logout');
        $request->setSession($session = new Session(new MockArraySessionStorage()));
        $event = new LogoutEvent($request, $this->token());
        $this->subscriber($this->credential(), ['/'])->onLogout($event);

        self::assertNull($event->getResponse());
        self::assertFalse($session->isStarted());
    }

    /**
     * SECURITY: every doubt keeps the plain local sign-out, and the hint is removed anyway.
     */
    public function testEveryDoubtKeepsTheLocalSignOut(): void
    {
        $cases = [
            'no token'        => [$this->credential(), $this->event($this->hint(), 'https://app.example.com/', false)],
            'another user'    => [$this->credential(), $this->event(new RpLogoutHint('okta', 'client-placeholder', 'eve@example.com', 'https://idp.example.com/logout', null))],
            'credential gone' => [null, $this->event($this->hint())],
            'disabled'        => [$this->credential()->setEnabled(false), $this->event($this->hint())],
            'opted out'       => [$this->credential()->setRpInitiatedLogout(false), $this->event($this->hint())],
            'other client id' => [$this->credential()->setClientId('rotated-client'), $this->event($this->hint())],
            'not a redirect'  => [$this->credential(), $this->event($this->hint(), new JsonResponse(['ok' => true]))],
        ];
        foreach ($cases as $name => [$credential, $event]) {
            $before = $event->getResponse();
            $this->subscriber($credential, ['/'])->onLogout($event);
            self::assertSame($before, $event->getResponse(), $name);
            self::assertFalse($event->getRequest()->getSession()->has(RpLogoutSession::SESSION_KEY), $name);
        }
        self::assertSame([], $this->logger->records);
    }

    public function testAnEndpointThatNoLongerPassesTheRulesOrAnyErrorIsLoggedAndKeepsTheLocalSignOut(): void
    {
        // Audit ZDC-L1: the same rule as discovery, checked again at sign-out (no exception, nothing logged).
        foreach (['http://idp.example.com/logout', 'https://127.1/logout', 'https://[::1]/logout', 'https://intranet/logout', "https://idp.example.com/logout\r\nX: y"] as $endpoint) {
            $event = $this->event(new RpLogoutHint('okta', 'client-placeholder', 'ada@example.com', $endpoint, null));
            $this->subscriber($this->credential(), ['/'])->onLogout($event);
            self::assertSame('https://app.example.com/', $this->location($event), $endpoint);
        }
        self::assertCount(0, $this->logger->records);

        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findOneByProvider')->willThrowException(new RuntimeException('database down: secret detail'));
        $event = $this->event($this->hint());
        (new RpInitiatedLogoutSubscriber($this->hints, $credentials, ProfileRegistryFactory::requestResolver(TestUser::class), $this->logger))->onLogout($event);
        self::assertSame('https://app.example.com/', $this->location($event));

        self::assertSame([['AuthKit RP-initiated logout skipped; the local sign-out goes on.', ['exception' => RuntimeException::class]]], $this->logger->records, 'The class only, never the message.');
    }

    public function testANullResponseGetsTheProviderRedirect(): void
    {
        $event = $this->event($this->hint(), null);
        (new RpInitiatedLogoutSubscriber($this->hints, $this->repository($this->credential()), ProfileRegistryFactory::requestResolver(TestUser::class)))->onLogout($event);
        self::assertTrue(str_starts_with($this->location($event), 'https://idp.example.com/logout?'));
    }

    /**
     * @param list<mixed> $uris
     */
    private function subscriber(?SocialLoginCredential $credential, array $uris): RpInitiatedLogoutSubscriber
    {
        return new RpInitiatedLogoutSubscriber(
            $this->hints,
            $this->repository($credential),
            ProfileRegistryFactory::requestResolver(TestUser::class, ['social_login' => ['rp_logout' => ['post_logout_redirect_uris' => $uris]]]),
            $this->logger,
        );
    }

    private function repository(?SocialLoginCredential $credential): SocialLoginCredentialRepository
    {
        $credentials = $this->createStub(SocialLoginCredentialRepository::class);
        $credentials->method('findOneByProvider')->willReturn($credential);

        return $credentials;
    }

    private function credential(): SocialLoginCredential
    {
        return (new SocialLoginCredential())->setProvider('okta')->setClientId('client-placeholder')->setIssuer('https://idp.example.com')->setEnabled(true)->setRpInitiatedLogout(true);
    }

    private function hint(): RpLogoutHint
    {
        return new RpLogoutHint('okta', 'client-placeholder', 'ada@example.com', 'https://idp.example.com/logout', 'header.payload.signature');
    }

    private function token(): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser('ada@example.com', null), 'main');
    }

    private function event(?RpLogoutHint $hint, RedirectResponse|JsonResponse|string|null $response = 'https://app.example.com/', bool $signedIn = true): LogoutEvent
    {
        $session = new Session(new MockArraySessionStorage());
        if ($hint instanceof RpLogoutHint) {
            $this->hints->remember($session, $hint);
        }
        $request = Request::create('https://app.example.com/logout');
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'session-id');
        $event = new LogoutEvent($request, $signedIn ? $this->token() : null);
        if ($response !== null) {
            $event->setResponse($response instanceof RedirectResponse || $response instanceof JsonResponse ? $response : new RedirectResponse($response));
        }

        return $event;
    }

    private function location(LogoutEvent $event): string
    {
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response->getTargetUrl();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
