<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\DependencyInjection;

use Nowo\AuthKitBundle\DependencyInjection\Configuration;
use Nowo\AuthKitBundle\DependencyInjection\NowoAuthKitExtension;
use Nowo\AuthKitBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function array_filter;

/**
 * REQ-UI-003 configuration vocabulary: per-profile web_ui (layout_template, templates.<page>, form_themes). 2.0: the
 * templates.* / css.* aliases (deprecated since 1.27) and the css.* → theme.bundles.NowoAuthKitBundle bridge are removed;
 * the keys fail the build with their replacement.
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

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function removedKeys(): iterable
    {
        yield 'templates.layout' => [['templates' => ['layout' => 'auth/layout.html.twig']], Configuration::REMOVED_TEMPLATES_KEY];
        yield 'templates.<page>' => [['templates' => ['login' => 'auth/login.html.twig']], Configuration::REMOVED_TEMPLATES_KEY];
        yield 'templates.form_theme' => [['templates' => ['form_theme' => 'form/auth.html.twig']], Configuration::REMOVED_TEMPLATES_KEY];
        yield 'css.button_class' => [['css' => ['button_class' => 'btn btn-dark']], Configuration::REMOVED_CSS_KEY];
        yield 'css.secondary_button_class' => [['css' => ['secondary_button_class' => 'btn btn-light']], Configuration::REMOVED_CSS_KEY];
    }

    /**
     * @param array<string, mixed> $keys
     */
    #[DataProvider('removedKeys')]
    public function testTheRemovedKeysFailTheBuild(array $keys, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process([['user_class' => TestUser::class] + $keys]);
    }

    /** The root shorthand moves the keys into the default profile: same error. */
    public function testTheRemovedKeysFailTheBuildFromTheRootShorthandToo(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(Configuration::REMOVED_CSS_KEY);
        (new Processor())->processConfiguration(new Configuration(), [['user_class' => TestUser::class, 'css' => ['button_class' => 'btn']]]);
    }

    public function testTheFormerBundleLayoutMeansTheUiKitGuestLayout(): void
    {
        $webUi = $this->process([['user_class' => TestUser::class, 'web_ui' => ['layout_template' => Configuration::LEGACY_LAYOUT]]]);

        self::assertNull($webUi['profiles']['default']['web_ui']['layout_template']);
        self::assertSame(Configuration::LEGACY_LAYOUT, $webUi['profiles']['default']['templates']['layout']);
        self::assertSame(['button_class' => Configuration::DEFAULT_BUTTON_CLASS, 'secondary_button_class' => Configuration::DEFAULT_SECONDARY_BUTTON_CLASS], $webUi['profiles']['default']['css']);
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

    public function testNoCssBridgeIntoTheUiKitTheme(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->stubExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_auth_kit', ['profiles' => ['admin' => ['user_class' => TestUser::class]]]);
        (new NowoAuthKitExtension())->prepend($container);

        self::assertSame([], array_filter($container->getExtensionConfig('nowo_ui_kit'), static fn (array $config): bool => isset($config['theme']['bundles']['NowoAuthKitBundle'])));
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
