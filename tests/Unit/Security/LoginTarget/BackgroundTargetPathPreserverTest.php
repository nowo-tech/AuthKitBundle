<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security\LoginTarget;

use LogicException;
use Nowo\AuthKitBundle\Security\LoginTarget\BackgroundTargetPathPreserver;
use Nowo\AuthKitBundle\Tests\Support\FirewallSecurity;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Throwable;

final class BackgroundTargetPathPreserverTest extends TestCase
{
    private const KEY = '_security.main.target_path';

    public function testBackgroundCallDoesNotReplaceTheSavedPage(): void
    {
        $request = $this->backgroundRequest('/projects/7');
        $this->preserver()->onKernelException($this->exceptionEvent($request, new AccessDeniedException()));
        // What the firewall's exception listener does next.
        $request->getSession()->set(self::KEY, $request->getUri());

        $this->preserver()->onKernelResponse($this->responseEvent($request));

        self::assertSame('/projects/7', $request->getSession()->get(self::KEY));
        self::assertFalse($request->attributes->has(BackgroundTargetPathPreserver::ATTRIBUTE));
    }

    public function testBackgroundCallWithoutPreviousTargetLeavesNone(): void
    {
        $request = $this->backgroundRequest(null);
        $this->preserver()->onKernelException($this->exceptionEvent($request, new RuntimeException('x', 0, new AuthenticationCredentialsNotFoundException())));
        $request->getSession()->set(self::KEY, $request->getUri());

        $this->preserver()->onKernelResponse($this->responseEvent($request));

        self::assertFalse($request->getSession()->has(self::KEY));
    }

    public function testTargetChangedByAnotherPartyIsKept(): void
    {
        $request = $this->backgroundRequest('/projects/7');
        $this->preserver()->onKernelException($this->exceptionEvent($request, new AccessDeniedException()));
        $request->getSession()->set(self::KEY, '/somewhere-else');

        $this->preserver()->onKernelResponse($this->responseEvent($request));

        self::assertSame('/somewhere-else', $request->getSession()->get(self::KEY));
    }

    public function testNothingIsRememberedOutsideTheScope(): void
    {
        $navigation = $this->backgroundRequest('/projects/7');
        $navigation->headers->set('Sec-Fetch-Mode', 'navigate');
        $navigation->headers->set('Sec-Fetch-Dest', 'document');
        $cases = [
            'page navigation'      => [$navigation, new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('main', 'checker')],
            'sub request'          => [$this->backgroundRequest('/p'), new AccessDeniedException(), HttpKernelInterface::SUB_REQUEST, new FirewallConfig('main', 'checker')],
            'not a security error' => [$this->backgroundRequest('/p'), new LogicException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('main', 'checker')],
            'other firewall'       => [$this->backgroundRequest('/p'), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('api', 'checker')],
            'no firewall'          => [$this->backgroundRequest('/p'), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, null],
            'no session'           => [Request::create('http://example.com/api/poll'), new AccessDeniedException(), HttpKernelInterface::MAIN_REQUEST, new FirewallConfig('main', 'checker')],
        ];
        foreach ($cases as $name => [$request, $exception, $type, $config]) {
            (new BackgroundTargetPathPreserver(FirewallSecurity::with($config), ['main']))
                ->onKernelException($this->exceptionEvent($request, $exception, $type));
            self::assertFalse($request->attributes->has(BackgroundTargetPathPreserver::ATTRIBUTE), $name);
        }
    }

    public function testFirstCallWithoutPreviousSessionLeavesNoTarget(): void
    {
        $request = Request::create('http://example.com/api/poll');
        $request->headers->set('Sec-Fetch-Mode', 'cors');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->preserver()->onKernelException($this->exceptionEvent($request, new AccessDeniedException()));
        self::assertFalse($request->getSession()->isStarted(), 'The snapshot never starts a session.');

        $this->preserver()->onKernelResponse($this->responseEvent($request));
        self::assertFalse($request->getSession()->isStarted());

        $request->attributes->set(BackgroundTargetPathPreserver::ATTRIBUTE, ['firewall' => 'main', 'target' => null]);
        $request->getSession()->set(self::KEY, $request->getUri());
        $this->preserver()->onKernelResponse($this->responseEvent($request));
        self::assertFalse($request->getSession()->has(self::KEY));
    }

    public function testResponseWithoutSnapshotOrOnASubRequestIsIgnored(): void
    {
        $request = $this->backgroundRequest('/projects/7');
        $uri     = $request->getUri();
        $request->getSession()->set(self::KEY, $uri);
        $this->preserver()->onKernelResponse($this->responseEvent($request));
        self::assertSame($uri, $this->saved($request));

        $request->attributes->set(BackgroundTargetPathPreserver::ATTRIBUTE, ['firewall' => 'main', 'target' => '/projects/7']);
        $this->preserver()->onKernelResponse($this->responseEvent($request, HttpKernelInterface::SUB_REQUEST));
        self::assertSame($uri, $this->saved($request));

        $request->attributes->set(BackgroundTargetPathPreserver::ATTRIBUTE, ['target' => '/projects/7']);
        $this->preserver()->onKernelResponse($this->responseEvent($request));
        self::assertSame($uri, $this->saved($request));
    }

    /**
     * @phpstan-impure
     */
    private function saved(Request $request): mixed
    {
        return $request->getSession()->get(self::KEY);
    }

    private function preserver(): BackgroundTargetPathPreserver
    {
        return new BackgroundTargetPathPreserver(FirewallSecurity::with(new FirewallConfig('main', 'checker')), ['main']);
    }

    private function backgroundRequest(?string $saved): Request
    {
        $request = Request::create('http://example.com/api/poll');
        $request->headers->set('Sec-Fetch-Mode', 'cors');
        $request->headers->set('Sec-Fetch-Dest', 'empty');
        $session = new Session(new MockArraySessionStorage());
        if ($saved !== null) {
            $session->set(self::KEY, $saved);
        }
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'sid');

        return $request;
    }

    private function exceptionEvent(Request $request, Throwable $exception, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, $type, $exception);
    }

    private function responseEvent(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response('', 302));
    }
}
