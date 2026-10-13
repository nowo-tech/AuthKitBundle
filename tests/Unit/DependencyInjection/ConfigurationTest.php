<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
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
        self::assertFalse($config['profiles']['default']['slide_to_confirm']['enabled']);
        self::assertSame('gate', $config['profiles']['default']['slide_to_confirm']['registration_consent']);
        self::assertFalse($config['profiles']['default']['slide_to_confirm']['qr_login_approve']);
        self::assertFalse($config['profiles']['default']['device_intelligence']['enabled']);
        self::assertTrue($config['profiles']['default']['device_intelligence']['collect_on_auth_pages']);
        self::assertSame('/_device/collect', $config['profiles']['default']['device_intelligence']['collect_endpoint']);
        self::assertFalse($config['profiles']['default']['device_intelligence']['new_device_notify']);
        self::assertFalse($config['profiles']['default']['device_intelligence']['device_rate_limit']);
        self::assertFalse($config['profiles']['default']['device_intelligence']['qr_login']['approve_require_trusted']);
        self::assertFalse($config['profiles']['default']['otp_input']['enabled']);
        self::assertTrue($config['profiles']['default']['otp_input']['password_reset_code']);
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

    public function testPartialLaterBlockKeepsEarlierLocaleAndDefaultProfile(): void
    {
        // e.g. config/packages/prod/*.yaml overriding only registration_mode after the main file.
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [
            [
                'default_profile' => 'admin',
                'profiles'        => [
                    'admin' => [
                        'user_class' => TestUser::class,
                    ],
                ],
                'locale' => [
                    'in_path'     => 'both',
                    'default'     => 'es',
                    'enabled'     => ['es', 'en'],
                    'unlocalized' => 'serve',
                ],
            ],
            [
                'profiles' => [
                    'admin' => [
                        'registration_mode' => 'disabled',
                    ],
                ],
            ],
        ]);

        self::assertSame('admin', $config['default_profile']);
        self::assertSame('disabled', $config['profiles']['admin']['registration_mode']);
        self::assertSame(TestUser::class, $config['profiles']['admin']['user_class']);
        self::assertSame('both', $config['locale']['in_path']);
        self::assertSame('es', $config['locale']['default']);
        self::assertSame(['es', 'en'], $config['locale']['enabled']);
        self::assertSame('serve', $config['locale']['unlocalized']);
        self::assertSame('es', $config['default_locale']);
        self::assertTrue($config['locale_in_path']);
    }

    public function testPartialLaterLocaleBlockOnlyOverridesItsKeys(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [
            [
                'profiles' => [
                    'default' => [
                        'user_class' => TestUser::class,
                    ],
                ],
                'locale' => [
                    'in_path'     => 'both',
                    'default'     => 'es',
                    'unlocalized' => 'serve',
                ],
            ],
            [
                'locale' => [
                    'in_path' => true,
                ],
            ],
        ]);

        self::assertSame('always', $config['locale']['in_path']);
        self::assertSame('es', $config['locale']['default']);
        self::assertSame('serve', $config['locale']['unlocalized']);
    }

    public function testLegacyLocaleKeysMapToLocaleNode(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                ],
            ],
            'default_locale'  => 'es',
            'enabled_locales' => ['es', 'fr'],
        ]]);

        self::assertSame('never', $config['locale']['in_path']);
        self::assertSame('es', $config['locale']['default']);
        self::assertSame(['es', 'fr'], $config['locale']['enabled']);
        self::assertSame(['es', 'fr'], $config['enabled_locales']);
    }

    public function testDefaultProfileFallsBackToFirstProfile(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'staff' => [
                    'user_class' => TestUser::class,
                ],
            ],
        ]]);

        self::assertSame('staff', $config['default_profile']);
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

    public function testSlideToConfirmCanBeEnabled(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class'       => TestUser::class,
                    'slide_to_confirm' => [
                        'enabled'              => true,
                        'registration_consent' => 'legal',
                        'qr_login_approve'     => 'danger',
                    ],
                ],
            ],
        ]]);

        self::assertTrue($config['profiles']['default']['slide_to_confirm']['enabled']);
        self::assertSame('legal', $config['profiles']['default']['slide_to_confirm']['registration_consent']);
        self::assertSame('danger', $config['profiles']['default']['slide_to_confirm']['qr_login_approve']);
    }

    public function testDeviceIntelligenceCanBeEnabled(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class'          => TestUser::class,
                    'device_intelligence' => [
                        'enabled'           => true,
                        'new_device_notify' => true,
                        'device_rate_limit' => true,
                        'qr_login'          => ['approve_require_trusted' => true],
                    ],
                ],
            ],
        ]]);

        $di = $config['profiles']['default']['device_intelligence'];
        self::assertTrue($di['enabled']);
        self::assertTrue($di['new_device_notify']);
        self::assertTrue($di['device_rate_limit']);
        self::assertTrue($di['qr_login']['approve_require_trusted']);
    }

    public function testOtpInputCanBeEnabled(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class' => TestUser::class,
                    'otp_input'  => [
                        'enabled'             => true,
                        'password_reset_code' => false,
                    ],
                ],
            ],
        ]]);

        $otp = $config['profiles']['default']['otp_input'];
        self::assertTrue($otp['enabled']);
        self::assertFalse($otp['password_reset_code']);
    }

    public function testDeviceIntelligenceRejectsEmptyCollectEndpoint(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class'          => TestUser::class,
                    'device_intelligence' => ['collect_endpoint' => ''],
                ],
            ],
        ]]);
    }

    public function testSlideToConfirmQrLoginApproveRejectsInvalidValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'profiles' => [
                'default' => [
                    'user_class'       => TestUser::class,
                    'slide_to_confirm' => [
                        'qr_login_approve' => 1,
                    ],
                ],
            ],
        ]]);
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

    public function testAbsoluteUrlBaseUriDefaultsToNull(): void
    {
        self::assertNull($this->processBaseUri(null)['absolute_url']['base_uri']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function acceptedBaseUris(): iterable
    {
        yield 'https' => ['https://app.example.com'];
        yield 'port and path' => ['http://localhost:8010/portal/'];
        yield 'empty' => [''];
        yield 'env placeholder' => ['env_0123456789abcdef_DEFAULT_URI_0123456789abcdef0123456789abcdef'];
    }

    #[DataProvider('acceptedBaseUris')]
    public function testAbsoluteUrlBaseUriAccepts(mixed $baseUri): void
    {
        self::assertSame($baseUri, $this->processBaseUri($baseUri)['absolute_url']['base_uri']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function rejectedBaseUris(): iterable
    {
        yield 'relative' => ['/portal', 'expected an absolute URL'];
        yield 'query' => ['https://app.example.com/?x=1', 'query strings and fragments are not allowed.'];
        yield 'scheme' => ['ftp://app.example.com', 'the scheme must be http or https.'];
        yield 'not a string' => [true, 'expected a string or null.'];
    }

    #[DataProvider('rejectedBaseUris')]
    public function testAbsoluteUrlBaseUriRejects(mixed $baseUri, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->processBaseUri($baseUri);
    }

    /**
     * @return array<string, mixed>
     */
    private function processBaseUri(mixed $baseUri): array
    {
        $config = ['profiles' => ['default' => ['user_class' => TestUser::class]]];
        if ($baseUri !== null) {
            $config['absolute_url'] = ['base_uri' => $baseUri];
        }

        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    public function testSocialLoginPkceDefaultsToEmptyMapAndKeepsProviderKeys(): void
    {
        $processor = new Processor();
        $defaults  = $processor->processConfiguration(new Configuration(), [[
            'profiles' => ['default' => ['user_class' => TestUser::class]],
        ]]);
        self::assertSame([], $defaults['profiles']['default']['social_login']['pkce']);

        $config = $processor->processConfiguration(new Configuration(), [[
            'profiles' => ['default' => [
                'user_class'   => TestUser::class,
                'social_login' => ['pkce' => ['google' => false, 'my-idp' => true]],
            ]],
        ]]);
        self::assertSame(['google' => false, 'my-idp' => true], $config['profiles']['default']['social_login']['pkce']);
    }
}
