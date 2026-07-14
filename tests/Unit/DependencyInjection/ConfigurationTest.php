<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
        ]]);

        self::assertSame('default', $config['default_profile']);
        self::assertSame(TestUser::class, $config['profiles']['default']['user_class']);
        self::assertSame('email', $config['profiles']['default']['user_identifier_field']);
        self::assertSame('first_user_only', $config['profiles']['default']['registration_mode']);
        self::assertSame('nowo_auth_kit_login', $config['profiles']['default']['routes']['login']['name']);
        self::assertSame(['en', 'es'], $config['enabled_locales']);
        self::assertSame('disabled', $config['profiles']['default']['embed']['mode']);
        self::assertFalse($config['profiles']['default']['remember_me']['enabled']);
        self::assertSame(604800, $config['profiles']['default']['remember_me']['lifetime']);
        self::assertFalse($config['profiles']['default']['password_strength']['enabled']);
        self::assertSame('medium', $config['profiles']['default']['password_strength']['level']);
        self::assertTrue($config['profiles']['default']['embed']['show_login']);
        self::assertTrue($config['profiles']['default']['embed']['show_register']);
        self::assertFalse($config['locale_in_path']);
    }

    public function testRegistrationModeValues(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'user_class'        => TestUser::class,
            'registration_mode' => 'always',
            'registration_role' => 'ROLE_ADMIN',
        ]]);

        self::assertSame('always', $config['profiles']['default']['registration_mode']);
        self::assertSame('ROLE_ADMIN', $config['profiles']['default']['registration_role']);
    }

    public function testLegacyFlatConfigurationIsNormalizedToProfiles(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'user_class' => TestUser::class,
        ]]);

        self::assertSame(TestUser::class, $config['profiles']['default']['user_class']);
    }
}
