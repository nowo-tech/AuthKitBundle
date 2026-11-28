<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Saml;

use Nowo\AuthKitBundle\Saml\SamlFailure;
use Nowo\AuthKitBundle\Saml\SamlRequestStore;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(SamlRequestStore::class)]
#[CoversClass(SamlFailure::class)]
final class SamlRequestStoreTest extends TestCase
{
    private MockClock $clock;

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        $this->cache = new ArrayAdapter(0, true, 0, 0, $this->clock);
    }

    public function testAPendingRequestIsUsedOnce(): void
    {
        $session = $this->session();
        $relay   = SamlRequestStore::newRelayState();
        $this->store()->issue($session, 'default', 'acme', '_req-1', $relay, 600);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $relay);
        self::assertFalse($this->store()->isTestPending($session, 'default', 'acme', $relay));
        self::assertSame(['request_id' => '_req-1', 'test' => false], $this->store()->consume($session, 'default', 'acme', $relay));
        $this->assertRefused(SamlFailure::UNSOLICITED, $session, 'acme', $relay);
    }

    public function testATestRequestCarriesItsMarker(): void
    {
        $session = $this->session();
        $this->store()->issue($session, 'default', 'acme', '_req-1', 'relay-1', 600, true);

        self::assertTrue($this->store()->isTestPending($session, 'default', 'acme', 'relay-1'));
        self::assertFalse($this->store()->isTestPending($session, 'default', 'acme', 'relay-2'));
        self::assertFalse($this->store()->isTestPending($session, 'default', 'other', 'relay-1'));
        self::assertFalse($this->store()->isTestPending($session, 'admin', 'acme', 'relay-1'));
        self::assertFalse($this->store()->isTestPending($session, 'default', 'acme', ''));
        self::assertTrue($this->store()->consume($session, 'default', 'acme', 'relay-1')['test']);
    }

    public function testAMismatchLeavesThePendingRequestAlone(): void
    {
        $session = $this->session();
        $this->store()->issue($session, 'default', 'acme', '_req-1', 'relay-1', 600);

        $this->assertRefused(SamlFailure::UNSOLICITED, $session, 'other', 'relay-1');
        $this->assertRefused(SamlFailure::UNSOLICITED, $session, 'acme', 'relay-1', 'admin');
        $this->assertRefused(SamlFailure::INVALID_STATE, $session, 'acme', 'crafted');
        $this->assertRefused(SamlFailure::INVALID_STATE, $session, 'acme', '');
        self::assertSame('_req-1', $this->store()->consume($session, 'default', 'acme', 'relay-1')['request_id']);
    }

    public function testAnExpiredRequestIsRefusedAndBurned(): void
    {
        $session = $this->session();
        $this->store()->issue($session, 'default', 'acme', '_req-1', 'relay-1', 60);
        $this->clock->sleep(60);

        $this->assertRefused(SamlFailure::EXPIRED_REQUEST, $session, 'acme', 'relay-1');
        $this->assertRefused(SamlFailure::UNSOLICITED, $session, 'acme', 'relay-1');
    }

    public function testTheRequestIdIsClaimedOnceAcrossSessions(): void
    {
        // Two sessions that hold the same pending request (a copied session, a handler without locking).
        $first  = $this->session();
        $second = $this->session();
        $this->store()->issue($first, 'default', 'acme', '_req-1', 'relay-1', 600);
        $this->store()->issue($second, 'default', 'acme', '_req-1', 'relay-1', 600);

        $this->store()->consume($first, 'default', 'acme', 'relay-1');
        $this->assertRefused(SamlFailure::REPLAYED, $second, 'acme', 'relay-1');
    }

    public function testAnUnreachableClaimStoreFailsClosed(): void
    {
        $session = $this->session();
        $pool    = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('The cache is down.'));
        $store = new SamlRequestStore(new AuthKitAttemptLimiter($pool), $this->clock);
        $store->issue($session, 'default', 'acme', '_req-1', 'relay-1', 600);

        $this->expectException(RuntimeException::class);
        $store->consume($session, 'default', 'acme', 'relay-1');
    }

    public function testTheClaimOutlivesTheLifetimeAndTheLargestSkew(): void
    {
        $session = $this->session();
        $this->store()->issue($session, 'default', 'acme', '_req-1', 'relay-1', 600);
        $this->store()->consume($session, 'default', 'acme', 'relay-1');
        // Still claimed after lifetime + 180 s: a copy of the session cannot use it.
        $this->clock->sleep(600 + 180);
        $copy = $this->session();
        $copy->set(SamlRequestStore::SESSION_KEY, ['profile' => 'default', 'provider' => 'acme', 'request_id' => '_req-1', 'relay_state' => 'relay-1', 'expires' => $this->clock->now()->getTimestamp() + 10, 'lifetime' => 600, 'test' => false]);
        $this->assertRefused(SamlFailure::REPLAYED, $copy, 'acme', 'relay-1');
        // And released once the window is over.
        $this->clock->sleep(61);
        $copy->set(SamlRequestStore::SESSION_KEY, ['profile' => 'default', 'provider' => 'acme', 'request_id' => '_req-1', 'relay_state' => 'relay-1', 'expires' => $this->clock->now()->getTimestamp() + 10, 'lifetime' => 600, 'test' => false]);
        self::assertSame('_req-1', $this->store()->consume($copy, 'default', 'acme', 'relay-1')['request_id']);
    }

    public function testABrokenSessionValueIsNoPendingRequest(): void
    {
        foreach (['x', ['profile' => 'default', 'provider' => 'acme', 'request_id' => '_r', 'relay_state' => 'relay-1'], ['profile' => 'default', 'provider' => 'acme', 'request_id' => '', 'relay_state' => 'relay-1', 'expires' => 1, 'lifetime' => 1]] as $value) {
            $session = $this->session();
            $session->set(SamlRequestStore::SESSION_KEY, $value);
            $this->assertRefused(SamlFailure::UNSOLICITED, $session, 'acme', 'relay-1');
        }
        self::assertNotSame('', (new SamlFailure(SamlFailure::MALFORMED))->getMessage());
        self::assertContains(SamlFailure::REPLAYED, SamlFailure::REASONS);
    }

    private function store(): SamlRequestStore
    {
        return new SamlRequestStore(new AuthKitAttemptLimiter($this->cache), $this->clock);
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function assertRefused(string $reason, SessionInterface $session, string $provider, string $relay, string $profile = 'default'): void
    {
        try {
            $this->store()->consume($session, $profile, $provider, $relay);
            self::fail('Consumed.');
        } catch (SamlFailure $failure) {
            self::assertSame($reason, $failure->reason);
        }
    }
}
