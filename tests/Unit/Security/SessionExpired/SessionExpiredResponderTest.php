<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\SessionExpired;

use LogicException;
use Nowo\AuthKitBundle\Security\SessionExpired\SessionExpiredResponder;
use Nowo\AuthKitBundle\Tests\Support\FirewallSecurity;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Symfony\Component\Security\Core\Exception\LogoutException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function gc_collect_cycles;
use function json_decode;
use function memory_get_usage;

final class SessionExpiredResponderTest extends TestCase
{
    public function testBackgroundCallOfAnAnonymousSessionGetsTheProblem401(): void
    {
        $event = $this->event($this->fetch(), new AccessDeniedException());
        $this->responder(new TokenStorage())->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('session-expired', $response->headers->get('X-Auth-Kit'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401, 'code' => 'session_expired'], json_decode((string) $response->getContent(), true));
        self::assertTrue($event->isAllowingCustomResponseCode());
    }

    public function testCustomMarkerAndProblemMembers(): void
    {
        $responder = new SessionExpiredResponder(FirewallSecurity::with(new FirewallConfig('main', 'c')), new TokenStorage(), new AuthenticationTrustResolver(), ['main'], 'X-Beacon-Auth', 'expired', 'https://example.com/problems/session', 'Session expired', 'auth.session_expired');
        $response  = $responder->response();

        self::assertSame('expired', $response->headers->get('X-Beacon-Auth'));
        self::assertSame(['type' => 'https://example.com/problems/session', 'title' => 'Session expired', 'status' => 401, 'code' => 'auth.session_expired'], json_decode((string) $response->getContent(), true));
    }

    public function testRememberMeTokenHittingAFullAuthenticationCheckGetsThe401(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new RememberMeToken(new InMemoryUser('ada', null), 'main'));
        $event = $this->event($this->fetch(), new AccessDeniedException());
        $this->responder($tokens)->onKernelException($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
    }

    public function testFullyAuthenticatedDenialStaysWithTheFirewall(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main', ['ROLE_USER']));
        $event = $this->event($this->fetch(), new RuntimeException('x', 0, new AccessDeniedException()));
        $this->responder($tokens)->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    public function testAccountStatusExceptionClearsTheToken(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main', ['ROLE_USER']));
        $event = $this->event($this->fetch(), new DisabledException());
        $this->responder($tokens)->onKernelException($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertNull($tokens->getToken());
    }

    public function testAuthenticationExceptionKeepsTheToken(): void
    {
        $tokens = new TokenStorage();
        $token  = new UsernamePasswordToken(new InMemoryUser('ada', null), 'main', ['ROLE_USER']);
        $tokens->setToken($token);
        $event = $this->event($this->fetch(), new AuthenticationCredentialsNotFoundException());
        $this->responder($tokens)->onKernelException($event);

        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertSame($token, $tokens->getToken());
    }

    public function testOutOfScopeRequestsAreLeftToTheFirewall(): void
    {
        $navigation = Request::create('http://example.com/projects');
        $navigation->headers->set('Sec-Fetch-Mode', 'navigate');
        $config = new FirewallConfig('main', 'c');
        $cases  = [
            'page navigation'  => [$navigation, new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, $config],
            'sub request'      => [$this->fetch(), new AccessDeniedException(), HttpKernelInterface::SUB_REQUEST, $config],
            'no firewall'      => [$this->fetch(), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, null],
            'security off'     => [$this->fetch(), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('main', 'c', null, false)],
            'stateless'        => [$this->fetch(), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('main', 'c', null, true, true)],
            'other firewall'   => [$this->fetch(), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('api', 'c')],
            'not security'     => [$this->fetch(), new LogicException(), HttpKernelInterface::MAIN_REQUEST, $config],
            'lazy response'    => [$this->fetch(), new LazyResponseException(new Response()), HttpKernelInterface::MAIN_REQUEST, $config],
            'logout exception' => [$this->fetch(), new LogoutException('x', new AccessDeniedException()), HttpKernelInterface::MAIN_REQUEST, $config],
        ];
        foreach ($cases as $name => [$request, $exception, $type, $firewall]) {
            $event = $this->event($request, $exception, $type);
            (new SessionExpiredResponder(FirewallSecurity::with($firewall), new TokenStorage(), new AuthenticationTrustResolver(), ['main']))->onKernelException($event);
            self::assertNull($event->getResponse(), $name);
        }
    }

    /**
     * FrankenPHP worker mode (kernel kept, no reset): one responder answers many requests of signed-out and signed-in
     * users; each answer depends only on its own token, and memory stays flat.
     */
    public function testWorkerLoopKeepsNoStateAndFlatMemory(): void
    {
        $tokens    = new TokenStorage();
        $responder = $this->responder($tokens);
        $wrong     = 0;
        $baseline  = 0;
        for ($i = 0; $i < 3000; ++$i) {
            $signedIn = $i % 3 === 0;
            $tokens->setToken($signedIn ? new UsernamePasswordToken(new InMemoryUser('user' . ($i % 7), null), 'main') : null);
            $event = $this->event($this->fetch(), new AccessDeniedException());
            $responder->onKernelException($event);
            $wrong += ($event->getResponse()?->getStatusCode() === 401) === !$signedIn ? 0 : 1;
            if ($i === 999) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertSame(0, $wrong);
        self::assertLessThan(256 * 1024, memory_get_usage() - $baseline);
    }

    private function responder(TokenStorage $tokens): SessionExpiredResponder
    {
        return new SessionExpiredResponder(FirewallSecurity::with(new FirewallConfig('main', 'c')), $tokens, new AuthenticationTrustResolver(), ['main']);
    }

    private function fetch(): Request
    {
        $request = Request::create('http://example.com/api/poll');
        $request->headers->set('Sec-Fetch-Mode', 'cors');

        return $request;
    }

    private function event(Request $request, Throwable $exception, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, $type, $exception);
    }
}
