<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PasswordReset;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class PasswordResetGateTest extends TestCase
{
    public function testEnabled(): void
    {
        $gate = new PasswordResetGate(ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'enabled'],
        ]));

        self::assertTrue($gate->isEnabled());
    }

    public function testDisabled(): void
    {
        $gate = new PasswordResetGate(ProfileRegistryFactory::single(TestUser::class, [
            'password_reset' => ['mode' => 'disabled'],
        ]));

        self::assertFalse($gate->isEnabled());
    }
}
