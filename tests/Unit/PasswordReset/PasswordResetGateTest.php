<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use PHPUnit\Framework\TestCase;

final class PasswordResetGateTest extends TestCase
{
    public function testEnabled(): void
    {
        self::assertTrue((new PasswordResetGate('enabled'))->isEnabled());
    }

    public function testDisabled(): void
    {
        self::assertFalse((new PasswordResetGate('disabled'))->isEnabled());
    }
}
