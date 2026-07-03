<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Config;

use Nowo\AuthKitBundle\Config\RememberMeConfigResolver;
use PHPUnit\Framework\TestCase;

final class RememberMeConfigResolverTest extends TestCase
{
    public function testEnsureLoginFieldAddsRememberMeWhenEnabled(): void
    {
        $fields = RememberMeConfigResolver::ensureLoginField(['identifier', 'password'], true);

        self::assertSame(['identifier', 'password', 'remember_me'], $fields);
    }

    public function testIsFirewallEnabledWhenRememberMeConfigEnabled(): void
    {
        self::assertTrue(RememberMeConfigResolver::isFirewallEnabled(true, ['identifier', 'password']));
    }

    public function testIsFirewallEnabledWhenRememberMeInLoginFields(): void
    {
        self::assertTrue(RememberMeConfigResolver::isFirewallEnabled(false, ['identifier', 'password', 'remember_me']));
    }

    public function testIsFirewallEnabledForNormalizedFields(): void
    {
        self::assertTrue(RememberMeConfigResolver::isFirewallEnabledForNormalizedFields(false, [
            ['name' => '_remember_me', 'type' => 'checkbox', 'property' => null, 'hash' => false, 'required' => false, 'security_name' => '_remember_me'],
        ]));
    }
}
