<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\MagicLogin;

use Nowo\AuthKitBundle\MagicLogin\MagicLoginGate;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use Nowo\AuthKitBundle\Tests\Support\ProfileRegistryFactory;
use PHPUnit\Framework\TestCase;

final class MagicLoginGateTest extends TestCase
{
    public function testEnabled(): void
    {
        $gate = new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled'],
        ]));

        self::assertTrue($gate->isEnabled());
    }

    public function testDisabled(): void
    {
        $gate = new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'disabled'],
        ]));

        self::assertFalse($gate->isEnabled());
    }

    public function testEnabledWithNamedProfile(): void
    {
        $gate = new MagicLoginGate(ProfileRegistryFactory::single(TestUser::class, [
            'magic_login' => ['mode' => 'enabled'],
        ]));

        self::assertTrue($gate->isEnabled('default'));
    }
}
