<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'user_class' => 'App\\Entity\\User',
        ]]);

        self::assertSame('App\\Entity\\User', $config['user_class']);
        self::assertSame('email', $config['user_identifier_field']);
        self::assertSame('first_user_only', $config['registration_mode']);
        self::assertSame('nowo_auth_kit_login', $config['routes']['login']['name']);
        self::assertSame(['en', 'es'], $config['enabled_locales']);
        self::assertSame('disabled', $config['embed']['mode']);
        self::assertFalse($config['remember_me']['enabled']);
        self::assertSame(604800, $config['remember_me']['lifetime']);
        self::assertFalse($config['password_strength']['enabled']);
        self::assertSame('medium', $config['password_strength']['level']);
        self::assertTrue($config['embed']['show_login']);
        self::assertTrue($config['embed']['show_register']);
        self::assertFalse($config['locale_in_path']);
    }

    public function testRegistrationModeValues(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'user_class'        => 'App\\Entity\\User',
            'registration_mode' => 'always',
            'registration_role' => 'ROLE_ADMIN',
        ]]);

        self::assertSame('always', $config['registration_mode']);
        self::assertSame('ROLE_ADMIN', $config['registration_role']);
    }
}
