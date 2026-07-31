<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\QrLogin\QrLoginRateLimiter;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class QrLoginRateLimiterTest extends TestCase
{
    private QrLoginRateLimiter $limiter;

    protected function setUp(): void
    {
        $this->limiter = new QrLoginRateLimiter(
            new AuthKitAttemptLimiter(new ArrayAdapter()),
        );
    }

    public function testAllowCreateUnderLimit(): void
    {
        self::assertTrue($this->limiter->allowCreate('127.0.0.1', 5, 600));
    }

    public function testAllowCreateRejectsOverLimit(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->allowCreate('10.0.0.1', 3, 600);
        }

        self::assertFalse($this->limiter->allowCreate('10.0.0.1', 3, 600));
    }

    public function testAllowCreateDisabledWhenMaxIsZero(): void
    {
        self::assertTrue($this->limiter->allowCreate('127.0.0.1', 0, 600));
    }

    public function testAllowApproveUnderLimit(): void
    {
        self::assertTrue($this->limiter->allowApprove('challenge-123', 5));
    }

    public function testAllowApproveRejectsOverLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->limiter->allowApprove('challenge-456', 5);
        }

        self::assertFalse($this->limiter->allowApprove('challenge-456', 5));
    }

    public function testAllowApproveDisabledWhenMaxIsZero(): void
    {
        self::assertTrue($this->limiter->allowApprove('challenge-789', 0));
    }

    public function testResetApprove(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->limiter->allowApprove('challenge-reset', 5);
        }

        self::assertFalse($this->limiter->allowApprove('challenge-reset', 5));

        $this->limiter->resetApprove('challenge-reset');
        self::assertTrue($this->limiter->allowApprove('challenge-reset', 5));
    }
}
