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
        self::assertFalse($config['login_throttle_required']);
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
        self::assertSame(
            ['@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'],
            $config['profiles']['default']['templates']['form_theme'],
        );
        self::assertSame('nowo-auth-kit__button', $config['profiles']['default']['css']['button_class']);
        self::assertSame('nowo-auth-kit__social-button', $config['profiles']['default']['css']['secondary_button_class']);
        self::assertNull($config['outbound_mail_ready_checker']);
        self::assertFalse($config['locale_in_path']);
        self::assertSame('never', $config['locale']['in_path']);
        self::assertSame('en', $config['locale']['default']);
        self::assertSame(['en', 'es'], $config['locale']['enabled']);
        self::assertSame('redirect', $config['locale']['unlocalized']);
    }

    public function testLegacyLocaleInPathTrueMapsToAlways(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
            'locale_in_path' => true,
        ]]);

        self::assertSame('always', $config['locale']['in_path']);
        self::assertTrue($config['locale_in_path']);
    }

    public function testNestedLocaleBoth(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
            'locale' => [
                'in_path'     => 'both',
                'default'     => 'es',
                'enabled'     => ['es', 'en'],
                'unlocalized' => 'serve',
            ],
        ]]);

        self::assertSame('both', $config['locale']['in_path']);
        self::assertSame('es', $config['locale']['default']);
        self::assertSame('serve', $config['locale']['unlocalized']);
        self::assertSame('es', $config['default_locale']);
        self::assertTrue($config['locale_in_path']);
    }

    public function testNestedLocaleInPathBoolTrue(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
            'locale' => [
                'in_path' => true,
            ],
        ]]);

        self::assertSame('always', $config['locale']['in_path']);
    }

    public function testInvalidLegacyLocaleInPathFallsBackToNever(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
            'locale_in_path' => 99,
        ]]);

        self::assertSame('never', $config['locale']['in_path']);
        self::assertFalse($config['locale_in_path']);
    }

    public function testLegacyKeysWithLocaleNodeStillProcess(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
            'locale' => [
                'in_path' => 'both',
                'default' => 'es',
            ],
            'default_locale' => 'en',
            'locale_in_path' => true,
        ]]);

        self::assertSame('both', $config['locale']['in_path']);
        self::assertSame('es', $config['locale']['default']);
        self::assertSame('es', $config['default_locale']);
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

    public function testLoginThrottleRequiredCanBeEnabled(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'login_throttle_required' => true,
            'profiles'                => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
        ]]);

        self::assertTrue($config['login_throttle_required']);
    }

    public function testSingleFormThemeStringIsNormalizedToList(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                    'templates'  => [
                        'form_theme' => 'form/auth_kit_theme.html.twig',
                    ],
                ],
            ],
        ]]);

        self::assertSame(
            ['form/auth_kit_theme.html.twig'],
            $config['profiles']['default']['templates']['form_theme'],
        );
    }
}
