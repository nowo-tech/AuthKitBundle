<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function array_slice;
use function implode;

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
        $config = $this->process([[
            'user_class' => TestUser::class,
            'templates'  => [
                'layout'     => 'auth/layout.html.twig',
                'login'      => 'auth/login.html.twig',
                'register'   => '',
                'form_theme' => 'form/auth.html.twig',
            ],
        ]]);
        $profile = $config['profiles']['default'];

        self::assertSame('auth/layout.html.twig', $profile['web_ui']['layout_template']);
        self::assertSame('auth/login.html.twig', $profile['web_ui']['templates']['login']);
        self::assertSame(Configuration::PAGE_TEMPLATES['register'], $profile['web_ui']['templates']['register']);
        self::assertSame(['form/auth.html.twig'], $profile['web_ui']['form_themes']);
        self::assertSame('auth/layout.html.twig', $profile['templates']['layout']);
        self::assertSame('auth/login.html.twig', $profile['templates']['login']);
        self::assertSame(['form/auth.html.twig'], $profile['templates']['form_theme']);
        self::assertSame('The "layout" option at "nowo_auth_kit.profiles.default.templates" is deprecated, use "web_ui.layout_template" (null = UiKit guest layout).', $this->deprecation(['templates', 'layout']));
        self::assertSame('The "login" option at "nowo_auth_kit.profiles.default.templates" is deprecated, use "web_ui.templates.login".', $this->deprecation(['templates', 'login']));
        self::assertSame('The "form_theme" option at "nowo_auth_kit.profiles.default.templates" is deprecated, use "web_ui.form_themes".', $this->deprecation(['templates', 'form_theme']));
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
        $config = $this->process([['user_class' => TestUser::class, 'css' => ['button_class' => 'btn btn-dark']]]);
        self::assertSame('btn btn-dark', $config['profiles']['default']['css']['button_class']);

        self::assertSame('The "button_class" option at "nowo_auth_kit.profiles.default.css" is deprecated, use "nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary".', $this->deprecation(['css', 'button_class']));
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
        self::assertSame([Configuration::UI_KIT_FORM_THEME, Configuration::AUTH_KIT_PASSWORD_TOGGLE_FORM_THEME], Configuration::defaultFormThemes(['NowoPasswordToggleBundle' => 'Nowo\\PasswordToggleBundle\\NowoPasswordToggleBundle']));
        // without the kernel bundle list: installed = available (dev dependency here)
        self::assertSame([Configuration::UI_KIT_FORM_THEME, Configuration::AUTH_KIT_PASSWORD_TOGGLE_FORM_THEME], Configuration::defaultFormThemes());
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
     *
     * @return array<string, mixed>
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }

    /**
     * Deprecation message of a per-profile option (e.g. ['templates', 'layout']), "%node%" / "%path%" resolved.
     *
     * @param list<string> $path
     */
    private function deprecation(array $path): ?string
    {
        $root = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $profiles = $root->getChildren()['profiles'];
        self::assertInstanceOf(PrototypedArrayNode::class, $profiles);
        $node = $profiles->getPrototype();
        foreach ($path as $name) {
            self::assertInstanceOf(ArrayNode::class, $node);
            $node = $node->getChildren()[$name];
        }

        self::assertInstanceOf(BaseNode::class, $node);

        return $node->isDeprecated() ? $node->getDeprecation($node->getName(), 'nowo_auth_kit.profiles.default.' . implode('.', array_slice($path, 0, -1)))['message'] : null;
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
