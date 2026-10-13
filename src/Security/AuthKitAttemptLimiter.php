<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Sliding fixed-window attempt counter backed by PSR-6 cache (rate limits / OTP lockout).
 *
 * PSR-6 has no atomic increment, so {@see hit()} and {@see consume()} are a read-modify-write.
 * When symfony/lock is installed and a `lock.factory` service exists, each key is serialized
 * with a blocking lock so concurrent requests cannot overshoot the limit. Without a lock
 * factory the counter is best-effort: parallel requests may each read the same count and
 * slip a few attempts past the limit. On multi-host deployments configure a shared lock store
 * (Redis, PDO, …) — the default `flock` store only serializes requests on one host.
 */
final class AuthKitAttemptLimiter
{
    /** Seconds a held lock survives if the process dies before releasing it. */
    private const LOCK_TTL = 10.0;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ?LockFactory $lockFactory = null,
    ) {
    }

    /**
     * @return bool true when under the limit (caller may proceed)
     */
    public function isAllowed(string $key, int $maxAttempts): bool
    {
        if ($maxAttempts < 1) {
            return true;
        }

        return $this->count($key) < $maxAttempts;
    }

    public function hit(string $key, int $windowSeconds): void
    {
        $this->synchronized($key, function () use ($key, $windowSeconds): void {
            $this->increment($key, $windowSeconds);
        });
    }

    /**
     * Consume one slot for rate limiting. Returns false when already at/over limit.
     *
     * The check and the increment run under the same per-key lock when a lock factory is available.
     */
    public function consume(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if ($maxAttempts < 1) {
            return true;
        }

        return $this->synchronized($key, function () use ($key, $maxAttempts, $windowSeconds): bool {
            if (!$this->isAllowed($key, $maxAttempts)) {
                return false;
            }

            $this->increment($key, $windowSeconds);

            return true;
        });
    }

    public function reset(string $key): void
    {
        $this->cache->deleteItem($this->normalizeKey($key));
    }

    public function count(string $key): int
    {
        $item = $this->cache->getItem($this->normalizeKey($key));

        return $item->isHit() ? (int) $item->get() : 0;
    }

    private function increment(string $key, int $windowSeconds): void
    {
        if ($windowSeconds < 1) {
            $windowSeconds = 60;
        }

        $item  = $this->cache->getItem($this->normalizeKey($key));
        $count = $item->isHit() ? (int) $item->get() : 0;
        $item->set($count + 1);
        $item->expiresAfter($windowSeconds);
        $this->cache->save($item);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function synchronized(string $key, callable $callback): mixed
    {
        if (!$this->lockFactory instanceof LockFactory) {
            return $callback();
        }

        $lock = $this->lockFactory->createLock($this->normalizeKey($key) . '_lock', self::LOCK_TTL);
        $lock->acquire(true);

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function normalizeKey(string $key): string
    {
        return 'auth_kit_limit_' . hash('sha256', $key);
    }
}
