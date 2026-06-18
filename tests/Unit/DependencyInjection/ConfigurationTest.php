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
