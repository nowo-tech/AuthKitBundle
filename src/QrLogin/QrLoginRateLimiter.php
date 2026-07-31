<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;

/**
 * Rate limiter wrapper for QR login operations using the existing AuthKitAttemptLimiter.
 */
final class QrLoginRateLimiter
{
    public function __construct(
        private readonly AuthKitAttemptLimiter $limiter,
    ) {
    }

    public function allowCreate(string $clientIp, int $maxAttempts, int $windowSeconds): bool
    {
        if ($maxAttempts < 1) {
            return true;
        }

        $key = 'qr_create:' . $clientIp;

        return $this->limiter->consume($key, $maxAttempts, $windowSeconds);
    }

    public function allowApprove(string $challengeId, int $maxAttempts): bool
    {
        if ($maxAttempts < 1) {
            return true;
        }

        $key = 'qr_approve:' . $challengeId;

        return $this->limiter->consume($key, $maxAttempts, 86400);
    }

    public function resetApprove(string $challengeId): void
    {
        $this->limiter->reset('qr_approve:' . $challengeId);
    }
}
