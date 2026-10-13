<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use ArrayObject;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

final class AuthKitAttemptLimiterTest extends TestCase
{
    public function testConsumeRespectsLimit(): void
    {
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter());

        self::assertTrue($limiter->consume('k', 2, 60));
        self::assertTrue($limiter->consume('k', 2, 60));
        self::assertFalse($limiter->consume('k', 2, 60));
        self::assertSame(2, $limiter->count('k'));
    }

    public function testZeroMaxAttemptsDisablesLimit(): void
    {
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter());

        self::assertTrue($limiter->consume('k', 0, 60));
        self::assertTrue($limiter->isAllowed('k', 0));
    }

    public function testResetClearsCounter(): void
    {
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter());
        $limiter->hit('k', 60);
        $limiter->reset('k');

        self::assertSame(0, $limiter->count('k'));
        self::assertTrue($limiter->isAllowed('k', 1));
    }

    public function testZeroWindowSecondsDefaultsToSixty(): void
    {
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter());
        $limiter->hit('k', 0);

        self::assertSame(1, $limiter->count('k'));
    }

    public function testConsumeWithLockFactoryRespectsLimit(): void
    {
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter(), new LockFactory(new InMemoryStore()));

        self::assertTrue($limiter->consume('k', 2, 60));
        self::assertTrue($limiter->consume('k', 2, 60));
        self::assertFalse($limiter->consume('k', 2, 60));
        self::assertSame(2, $limiter->count('k'));
    }

    public function testConsumeChecksAndIncrementsWhileHoldingThePerKeyLock(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events  = new ArrayObject();
        $store   = new RecordingLockStore($events);
        $cache   = new RecordingArrayAdapter($events);
        $limiter = new AuthKitAttemptLimiter($cache, new LockFactory($store));

        self::assertTrue($limiter->consume('k', 5, 60));

        self::assertSame('lock:save', $events->getArrayCopy()[0] ?? null);
        self::assertSame('lock:delete', array_reverse($events->getArrayCopy())[0] ?? null);
        self::assertContains('cache:get', $events->getArrayCopy());
        self::assertContains('cache:save', $events->getArrayCopy());
        self::assertCount(1, array_unique($store->resources));
        self::assertStringEndsWith('_lock', $store->resources[0]);
    }

    public function testDeniedConsumeStillReleasesTheLock(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events  = new ArrayObject();
        $store   = new RecordingLockStore($events);
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter(), new LockFactory($store));

        self::assertTrue($limiter->consume('k', 1, 60));
        $events->exchangeArray([]);
        self::assertFalse($limiter->consume('k', 1, 60));

        self::assertSame(['lock:save', 'lock:delete'], $events->getArrayCopy());
    }

    public function testHitWithLockFactoryIncrementsUnderLock(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events  = new ArrayObject();
        $store   = new RecordingLockStore($events);
        $limiter = new AuthKitAttemptLimiter(new ArrayAdapter(), new LockFactory($store));

        $limiter->hit('k', 0);

        self::assertSame(1, $limiter->count('k'));
        self::assertSame(['lock:save', 'lock:delete'], $events->getArrayCopy());
    }

    public function testLockIsReleasedWhenTheCacheThrows(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();
        $store  = new RecordingLockStore($events);
        $cache  = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new RuntimeException('cache down'));
        $limiter = new AuthKitAttemptLimiter($cache, new LockFactory($store));

        try {
            $limiter->consume('k', 1, 60);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('cache down', $e->getMessage());
        }

        self::assertSame(['lock:save', 'lock:delete'], $events->getArrayCopy());
    }
}

/**
 * @internal
 */
final class RecordingLockStore implements PersistingStoreInterface
{
    /** @var list<string> */
    public array $resources = [];

    private readonly InMemoryStore $inner;

    /**
     * @param ArrayObject<int, string> $events
     */
    public function __construct(private readonly ArrayObject $events)
    {
        $this->inner = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        $this->events[]    = 'lock:save';
        $this->resources[] = (string) $key;
        $this->inner->save($key);
    }

    public function delete(Key $key): void
    {
        $this->events[] = 'lock:delete';
        $this->inner->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->inner->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->inner->putOffExpiration($key, $ttl);
    }
}

/**
 * @internal
 */
final class RecordingArrayAdapter implements CacheItemPoolInterface
{
    private readonly ArrayAdapter $inner;

    /**
     * @param ArrayObject<int, string> $events
     */
    public function __construct(private readonly ArrayObject $events)
    {
        $this->inner = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->events[] = 'cache:get';

        return $this->inner->getItem($key);
    }

    /**
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->inner->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->events[] = 'cache:save';

        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
