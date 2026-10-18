<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * REQ-UI-003 configuration vocabulary: per-profile web_ui (layout_template, templates.<page>, form_themes), the
 * deprecated templates.* / css.* aliases and the css.* → theme.bundles.NowoAuthKitBundle bridge.
 */
final class WebUiConfigurationTest extends TestCase
{
    public function testWebUiDefaults(): void
    {
        $config = $this->process([['user_class' => TestUser::class]]);
        $webUi  = $config['profiles']['default']['web_ui'];

        self::assertNull($webUi['layout_template']);
        self::assertSame(Configuration::PAGE_TEMPLATES, $webUi['templates']);
        self::assertNull($webUi['form_themes']);
        self::assertSame(['layout' => Configuration::LEGACY_LAYOUT] + Configuration::PAGE_TEMPLATES + ['form_theme' => null], $config['profiles']['default']['templates']);
    }

    public function testLegacyTemplatesMapToWebUiWithDeprecations(): void
    {
        $deprecations = [];
        $config       = $this->process([[
            'user_class' => TestUser::class,
            'templates'  => [
                'layout'     => 'auth/layout.html.twig',
                'login'      => 'auth/login.html.twig',
                'register'   => '',
                'form_theme' => 'form/auth.html.twig',
            ],
        ]], $deprecations);
        $profile = $config['profiles']['default'];

        self::assertSame('auth/layout.html.twig', $profile['web_ui']['layout_template']);
        self::assertSame('auth/login.html.twig', $profile['web_ui']['templates']['login']);
        self::assertSame(Configuration::PAGE_TEMPLATES['register'], $profile['web_ui']['templates']['register']);
        self::assertSame(['form/auth.html.twig'], $profile['web_ui']['form_themes']);
        self::assertSame('auth/layout.html.twig', $profile['templates']['layout']);
        self::assertSame('auth/login.html.twig', $profile['templates']['login']);
        self::assertSame(['form/auth.html.twig'], $profile['templates']['form_theme']);
        self::assertContains('Since nowo-tech/auth-kit-bundle 1.27.0: The "layout" option at "nowo_auth_kit.profiles.default.templates" is deprecated, use "web_ui.layout_template" (null = UiKit guest layout).', $deprecations);
        self::assertContains('Since nowo-tech/auth-kit-bundle 1.27.0: The "login" option at "nowo_auth_kit.profiles.default.templates" is deprecated, use "web_ui.templates.login".', $deprecations);
        self::assertContains('Since nowo-tech/auth-kit-bundle 1.27.0: The "form_theme" option at "nowo_auth_kit.profiles.default.templates" is deprecated, use "web_ui.form_themes".', $deprecations);
    }

    public function testWebUiWinsOverLegacyTemplates(): void
    {
        $config = $this->process([[
            'user_class' => TestUser::class,
            'templates'  => ['layout' => 'old/layout.html.twig', 'login' => 'old/login.html.twig', 'form_theme' => ['old/form.html.twig']],
            'web_ui'     => ['layout_template' => 'new/layout.html.twig', 'templates' => ['login' => 'new/login.html.twig'], 'form_themes' => 'new/form.html.twig'],
        ]]);
        $webUi = $config['profiles']['default']['web_ui'];

        self::assertSame('new/layout.html.twig', $webUi['layout_template']);
        self::assertSame('new/login.html.twig', $webUi['templates']['login']);
        self::assertSame(['new/form.html.twig'], $webUi['form_themes']);
    }

    public function testTheDeprecatedBundleLayoutMeansTheUiKitGuestLayout(): void
    {
        $legacy = $this->process([['user_class' => TestUser::class, 'templates' => ['layout' => Configuration::LEGACY_LAYOUT]]]);
        $webUi  = $this->process([['user_class' => TestUser::class, 'web_ui' => ['layout_template' => Configuration::LEGACY_LAYOUT]]]);

        self::assertNull($legacy['profiles']['default']['web_ui']['layout_template']);
        self::assertNull($webUi['profiles']['default']['web_ui']['layout_template']);
        self::assertSame(Configuration::LEGACY_LAYOUT, $webUi['profiles']['default']['templates']['layout']);
    }

    public function testCssButtonClassesAreDeprecated(): void
    {
        $deprecations = [];
        $this->process([['user_class' => TestUser::class, 'css' => ['button_class' => 'btn btn-dark']]], $deprecations);

        self::assertContains('Since nowo-tech/auth-kit-bundle 1.27.0: The "button_class" option at "nowo_auth_kit.profiles.default.css" is deprecated, use "nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary".', $deprecations);
    }

    public function testStimulusControllerMustBeAnIdentifier(): void
    {
        $config = $this->process([['user_class' => TestUser::class, 'web_ui' => ['qr_login' => ['stimulus_controller' => 'qr-login']]]]);
        self::assertSame('qr-login', $config['profiles']['default']['web_ui']['qr_login']['stimulus_controller']);

        $this->expectException(InvalidConfigurationException::class);
        $this->process([['user_class' => TestUser::class, 'web_ui' => ['qr_login' => ['stimulus_controller' => 'Qr Login']]]]);
    }

    public function testDefaultFormThemesFollowTheRegisteredBundles(): void
    {
        self::assertSame([Configuration::UI_KIT_FORM_THEME], Configuration::defaultFormThemes([]));
        self::assertSame([Configuration::UI_KIT_FORM_THEME, Configuration::PASSWORD_TOGGLE_FORM_THEME], Configuration::defaultFormThemes(['NowoPasswordToggleBundle' => 'Nowo\\PasswordToggleBundle\\NowoPasswordToggleBundle']));
        // without the kernel bundle list: installed = available (dev dependency here)
        self::assertSame([Configuration::UI_KIT_FORM_THEME, Configuration::PASSWORD_TOGGLE_FORM_THEME], Configuration::defaultFormThemes());
    }

    public function testExtensionResolvesFormThemesAgainstKernelBundles(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);
        (new NowoAuthKitExtension())->load([['user_class' => TestUser::class]], $container);

        /** @var array<string, array<string, mixed>> $profiles */
        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        self::assertSame([Configuration::UI_KIT_FORM_THEME], $profiles['default']['web_ui']['form_themes']);
        self::assertSame([Configuration::UI_KIT_FORM_THEME], $profiles['default']['templates']['form_theme']);
        self::assertFalse($container->getParameter('nowo_auth_kit.password_toggle_assets'));
    }

    public function testCssClassesAreBridgedIntoTheUiKitThemeException(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => ['admin' => ['css' => ['secondary_button_class' => 'btn btn-light']], 'broken' => 'x']]);
        $container->prependExtensionConfig('nowo_auth_kit', ['css' => ['button_class' => 'btn btn-dark', 'secondary_button_class' => Configuration::DEFAULT_SECONDARY_BUTTON_CLASS]]);

        (new NowoAuthKitExtension())->prepend($container);

        self::assertSame(
            [['theme' => ['bundles' => ['NowoAuthKitBundle' => ['classes' => ['btn_primary' => 'btn btn-dark', 'btn_secondary' => 'btn btn-light']]]]]],
            $container->getExtensionConfig('nowo_ui_kit'),
        );
    }

    public function testNoBridgeWithDefaultCssOrWithoutUiKit(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_auth_kit', ['css' => ['button_class' => Configuration::DEFAULT_BUTTON_CLASS]]);
        (new NowoAuthKitExtension())->prepend($container);
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));

        $withoutUiKit = new ContainerBuilder();
        $withoutUiKit->prependExtensionConfig('nowo_auth_kit', ['css' => ['button_class' => 'btn btn-dark']]);
        (new NowoAuthKitExtension())->prepend($withoutUiKit);
        self::assertFalse($withoutUiKit->hasExtension('nowo_ui_kit'));
    }

    /**
     * @param list<array<string, mixed>> $configs
     * @param list<string> $deprecations
     *
     * @return array<string, mixed>
     */
    private function process(array $configs, array &$deprecations = []): array
    {
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            /** @var ConfigurationInterface $configuration */
            $configuration = new Configuration();

            return (new Processor())->processConfiguration($configuration, $configs);
        } finally {
            restore_error_handler();
        }
    }

    private function stubExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
