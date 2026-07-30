<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Sliding fixed-window attempt counter backed by PSR-6 cache (rate limits / OTP lockout).
 */
final class AuthKitAttemptLimiter
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
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
     * Consume one slot for rate limiting. Returns false when already at/over limit.
     */
    public function consume(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if ($maxAttempts < 1) {
            return true;
        }

        if (!$this->isAllowed($key, $maxAttempts)) {
            return false;
        }

        $this->hit($key, $windowSeconds);

        return true;
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

    private function normalizeKey(string $key): string
    {
        return 'auth_kit_limit_' . hash('sha256', $key);
    }
}
