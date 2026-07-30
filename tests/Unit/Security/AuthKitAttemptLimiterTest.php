<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Security;

use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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
}
