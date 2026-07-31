<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\QrLogin;

use Nowo\AuthKitBundle\QrLogin\QrLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class QrLoginGateTest extends TestCase
{
    public function testEnabledWhenModeIsEnabled(): void
    {
        $gate = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'qr_login' => ['mode' => 'enabled'],
        ]));

        self::assertTrue($gate->isEnabled());
    }

    public function testDisabledWhenModeIsDisabled(): void
    {
        $gate = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'qr_login' => ['mode' => 'disabled'],
        ]));

        self::assertFalse($gate->isEnabled());
    }

    public function testEnabledWithNamedProfile(): void
    {
        $gate = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'qr_login' => ['mode' => 'enabled'],
        ]));

        self::assertTrue($gate->isEnabled('default'));
    }

    public function testDisabledByDefault(): void
    {
        $gate = new QrLoginGate(ProfileRegistryFactory::single(TestUser::class));

        self::assertFalse($gate->isEnabled());
    }
}
