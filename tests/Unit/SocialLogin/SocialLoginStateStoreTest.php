<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\SocialLogin\SocialLoginStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SocialLoginStateStoreTest extends TestCase
{
    public function testIssueAndConsume(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $store = new SocialLoginStateStore($stack);
        $state = $store->issue('google');

        self::assertTrue($store->consume('google', $state));
        self::assertFalse($store->consume('google', $state));
    }

    public function testRejectsMismatchedProvider(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $store = new SocialLoginStateStore($stack);
        $state = $store->issue('google');

        self::assertFalse($store->consume('github', $state));
    }

    public function testConsumePendingReturnsStoredCodeVerifierOnce(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $store = new SocialLoginStateStore($stack);
        $state = $store->issue('google', 'verifier-1');

        self::assertSame(['code_verifier' => 'verifier-1'], $store->consumePending('google', $state));
        self::assertNull($store->consumePending('google', $state));
    }

    public function testConsumePendingWithoutVerifierAndMismatches(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = new RequestStack();
        $stack->push($request);

        $store = new SocialLoginStateStore($stack);
        self::assertNull($store->consumePending('google', 'nothing-issued'));

        $state = $store->issue('google', '');
        self::assertSame(['code_verifier' => null], $store->consumePending('google', $state));

        $state = $store->issue('google', 'verifier-2');
        self::assertNull($store->consumePending('google', $state . 'x'));
        self::assertNull($store->consumePending('google', $state), 'A failed attempt must still burn the pending state.');
    }
}
