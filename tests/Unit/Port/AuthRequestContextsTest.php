<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Port;

use Nowo\AuthKitBundle\Port\AuthRequestContexts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AuthRequestContextsTest extends TestCase
{
    public function testFromRequestWithSession(): void
    {
        $request = Request::create('/qr/approve?q=1&list[]=a', 'POST', ['pin' => '1234'], [], [], ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'Agent/1', 'HTTP_X_STEP_UP' => 'ok']);
        $request->attributes->set('_route', 'nowo_auth_kit_qr_approve');
        $request->setLocale('es');
        $session = new Session(new MockArraySessionStorage());
        $session->set('unlock', 'yes');
        $request->setSession($session);

        $context = AuthRequestContexts::fromRequest($request, 'main', 'default');

        self::assertSame('POST', $context->method);
        self::assertSame('/qr/approve', $context->path);
        self::assertSame('nowo_auth_kit_qr_approve', $context->routeName);
        self::assertSame('10.1.2.3', $context->clientIp);
        self::assertSame('Agent/1', $context->userAgent);
        self::assertSame('es', $context->locale);
        self::assertSame('main', $context->firewall);
        self::assertSame('default', $context->profile);
        self::assertSame('ok', $context->header('X-Step-Up'));
        self::assertSame('1', $context->query('q'));
        self::assertNull($context->query('list'));
        self::assertSame('1234', $context->post('pin'));
        self::assertTrue($context->sessionHas('unlock'));
        self::assertSame('yes', $context->sessionGet('unlock'));
        self::assertSame('yes', $context->sessionRemove('unlock'));
        self::assertFalse($session->has('unlock'));
    }

    public function testFromRequestWithoutSessionOrRoute(): void
    {
        $context = AuthRequestContexts::fromRequest(Request::create('/'));

        self::assertNull($context->routeName);
        self::assertNull($context->firewall);
        self::assertNull($context->profile);
        self::assertFalse($context->sessionHas('k'));
        self::assertNull($context->sessionGet('k'));
        self::assertNull($context->sessionRemove('k'));
    }
}
