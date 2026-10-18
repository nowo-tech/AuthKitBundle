<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Enum\MagicLoginMode;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Enum\PasswordResetMode;
use Nowo\AuthKitBundle\Enum\QrLoginApproveMode;
use Nowo\AuthKitBundle\Enum\QrLoginDesktopBinding;
use Nowo\AuthKitBundle\Enum\QrLoginMode;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Enum\SocialLoginMode;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_values;
use function class_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function sprintf;
use function trigger_deprecation;
use function trim;

/**
 * Configuration tree for AuthKitBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_auth_kit';

    /** Deprecated 1.x layout (a bridge to the UiKit guest layout since 1.27); as a layout override it means null. */
    public const LEGACY_LAYOUT = '@NowoAuthKitBundle/layout.html.twig';

    public const UI_KIT_FORM_THEME = '@NowoUiKit/form/nowo_ui_layout.html.twig';

    public const PASSWORD_TOGGLE_FORM_THEME = '@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig';

    /** PasswordToggle widget theme with the UiKit `input` slot on the password input (uses PASSWORD_TOGGLE_FORM_THEME). */
    public const AUTH_KIT_PASSWORD_TOGGLE_FORM_THEME = '@NowoAuthKitBundle/form/password_toggle_widget.html.twig';

    public const PASSWORD_TOGGLE_TYPE = 'Nowo\\PasswordToggleBundle\\Form\\Type\\PasswordType';

    public const DEFAULT_BUTTON_CLASS = 'nowo-auth-kit__button';

    public const DEFAULT_SECONDARY_BUTTON_CLASS = 'nowo-auth-kit__social-button';

    /** Auth pages => default template (web_ui.templates.<page>). */
    public const PAGE_TEMPLATES = [
        'login'               => '@NowoAuthKitBundle/security/login.html.twig',
        'register'            => '@NowoAuthKitBundle/security/register.html.twig',
        'reset_request'       => '@NowoAuthKitBundle/security/reset_request.html.twig',
        'reset_password'      => '@NowoAuthKitBundle/security/reset_password.html.twig',
        'reset_password_code' => '@NowoAuthKitBundle/security/reset_password_code.html.twig',
        'magic_login_request' => '@NowoAuthKitBundle/security/magic_login_request.html.twig',
        'magic_login_confirm' => '@NowoAuthKitBundle/security/magic_login_confirm.html.twig',
        'qr_login_show'       => '@NowoAuthKitBundle/security/qr_login_show.html.twig',
        'qr_login_approve'    => '@NowoAuthKitBundle/security/qr_login_approve.html.twig',
    ];

    /** @var list<string> */
    private const PROFILE_KEYS = [
        'user_class',
        'user_identifier_field',
        'registration_role',
        'registration_mode',
        'registration_rate_limit',
        'registration_rate_window',
        'login_fields',
        'remember_me',
        'password_strength',
        'slide_to_confirm',
        'device_intelligence',
        'otp_input',
        'registration_fields',
        'web_ui',
        'templates',
        'css',
        'embed',
        'password_reset',
        'magic_login',
        'social_login',
        'qr_login',
        'routes',
        'firewall',
        'login_success_route',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->beforeNormalization()
                ->always()
                ->then(static function (?array $config): array {
                    $config ??= [];

                    if (!isset($config['profiles'])) {
                        $profile = [];
                        foreach (self::PROFILE_KEYS as $key) {
                            if (array_key_exists($key, $config)) {
                                $profile[$key] = $config[$key];
                                unset($config[$key]);
                            }
                        }

                        $config['profiles'] = ['default' => $profile];
                    }

                    // Each config block (one per file / env) is normalized on its own before the blocks are
                    // merged, so only map keys this block sets: injecting defaults here would let a later
                    // partial block (e.g. config/packages/prod/*.yaml) reset values set by an earlier one.
                    // Derived keys (default_profile, legacy mirrors) are filled after the merge (validate()).
                    $hasExplicitLocale = isset($config['locale']) && is_array($config['locale']);
                    $legacyLocale      = [];
                    if (array_key_exists('locale_in_path', $config)) {
                        $legacyInPath = $config['locale_in_path'];
                        if (is_bool($legacyInPath)) {
                            $legacyInPath = $legacyInPath ? 'always' : 'never';
                        } elseif (!is_string($legacyInPath) || !in_array($legacyInPath, ['never', 'always', 'both'], true)) {
                            $legacyInPath = 'never';
                        }
                        $legacyLocale['in_path'] = $legacyInPath;
                    }
                    if (array_key_exists('default_locale', $config)) {
                        $legacyLocale['default'] = $config['default_locale'];
                    }
                    if (array_key_exists('enabled_locales', $config)) {
                        $legacyLocale['enabled'] = $config['enabled_locales'];
                    }

                    if ($hasExplicitLocale && $legacyLocale !== []) {
                        trigger_deprecation(
                            'nowo-tech/auth-kit-bundle',
                            '1.7.0',
                            'Using "default_locale", "enabled_locales", and/or "locale_in_path" together with "locale" is deprecated; configure only the "locale" node. Nested "locale" values take precedence.',
                        );
                    }

                    if ($hasExplicitLocale) {
                        if (is_bool($config['locale']['in_path'] ?? null)) {
                            $config['locale']['in_path'] = $config['locale']['in_path'] ? 'always' : 'never';
                        }
                        $config['locale'] += $legacyLocale;
                    } elseif ($legacyLocale !== []) {
                        $config['locale'] = $legacyLocale;
                    }

                    return $config;
                })
            ->end()
            ->validate()
                ->always()
                ->then(static function (array $config): array {
                    if ($config['default_profile'] === null) {
                        $profileNames              = array_keys($config['profiles']);
                        $config['default_profile'] = $profileNames[0] ?? 'default';
                    }

                    // Legacy mirrors of the merged locale node (deprecated keys, kept for BC).
                    $config['default_locale']  = $config['locale']['default'];
                    $config['enabled_locales'] = $config['locale']['enabled'];
                    $config['locale_in_path']  = $config['locale']['in_path'] !== 'never';

                    return $config;
                })
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile name used when no profile is specified explicitly (default: first profile).')
                    ->defaultNull()
                ->end()
                ->scalarNode('outbound_mail_ready_checker')
                    ->info('Optional service id implementing OutboundMailReadyCheckerInterface for password-reset and magic-login UI hints.')
                    ->defaultNull()
                ->end()
                ->booleanNode('login_throttle_required')
                    ->info('When true, container compilation fails if nowo-tech/login-throttle-bundle is not registered (production hardening).')
                    ->defaultFalse()
                ->end()
                ->arrayNode('absolute_url')
                    ->info('Origin used for absolute URLs that leave the request (password-reset / magic-login links, QR login payload, social OAuth redirect_uri).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_uri')
                            ->info('Absolute http(s) URL (optional port and path prefix, no query/fragment), e.g. "%env(DEFAULT_URI)%". When set those links ignore the request Host header (password-reset poisoning hardening). null/empty: build them from the request (pre-1.24 behaviour; then configure framework.trusted_hosts).')
                            ->defaultNull()
                            ->example('https://app.example.com')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => self::baseUriError($value) !== null)
                                ->then(static function (mixed $value): never {
                                    throw new InvalidArgumentException(sprintf('Invalid "nowo_auth_kit.absolute_url.base_uri": %s', (string) self::baseUriError($value)));
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('flash')
                    ->info('Flash messages added by AuthKit controllers.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('translate')
                            ->info('Store flashes already translated (NowoAuthKitBundle domain, request locale) so any host flash template shows text. false keeps raw keys (pre-1.23).')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('twig_filter')
                            ->info('Register the flash_trans Twig filter (translates a flash key with the first domain of twig_filter_domains that has it).')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('twig_filter_domains')
                            ->info('Translation domains tried in order by flash_trans; keys found in none go through messages.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(['messages', 'NowoAuthKitBundle'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('recipient_locale')
                    ->info('Localize password-reset / magic-login / new-device notifications (context recipientLocale, generated links, translator) in the recipient account locale.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('No-op unless the user implements PreferredLocaleAwareInterface (or a custom resolver returns a locale) listed in locale.enabled.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('resolver')
                            ->info('Optional service id implementing RecipientLocaleResolverInterface (default reads PreferredLocaleAwareInterface).')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('create_admin')
                    ->info('nowo:auth-kit:create-admin console command.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('min_password_length')
                            ->info('Minimum password length accepted by the command (the profile password_strength policy also applies when enabled).')
                            ->defaultValue(12)
                            ->min(8)
                        ->end()
                        ->arrayNode('roles')
                            ->info('Roles assigned to the created user.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('profiles')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->validate()
                            ->always()
                            ->then(static fn (array $profile): array => self::resolveWebUi($profile))
                        ->end()
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->scalarNode('user_class')
                                ->info('FQCN of the application user entity (must implement UserInterface).')
                                ->defaultNull()
                                ->example('App\\Entity\\User')
                            ->end()
                            ->scalarNode('user_identifier_field')
                                ->info('Entity property used as the security user identifier (form_login username).')
                                ->defaultValue('email')
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('registration_role')
                                ->info('Role assigned to users created via registration (in addition to ROLE_USER from the entity).')
                                ->defaultValue('ROLE_USER')
                                ->cannotBeEmpty()
                            ->end()
                            ->enumNode('registration_mode')
                                ->values(array_map(static fn (RegistrationMode $mode): string => $mode->value, RegistrationMode::cases()))
                                ->info('disabled: no registration. first_user_only: register only when no users exist. always: open registration.')
                                ->defaultValue(RegistrationMode::FirstUserOnly->value)
                            ->end()
                            ->integerNode('registration_rate_limit')
                                ->info('Max registration POSTs per client IP per window (0 = disabled).')
                                ->defaultValue(5)
                                ->min(0)
                            ->end()
                            ->integerNode('registration_rate_window')
                                ->info('Seconds for registration_rate_limit window.')
                                ->defaultValue(900)
                                ->min(60)
                            ->end()
                            ->arrayNode('login_fields')
                                ->info('Login form fields. Use identifier (maps to user_identifier_field), password, remember_me.')
                                ->defaultValue(['identifier', 'password'])
                                ->prototype('variable')->end()
                            ->end()
                            ->append($this->createRememberMeNode())
                            ->append($this->createPasswordStrengthNode())
                            ->append($this->createSlideToConfirmNode())
                            ->append($this->createDeviceIntelligenceNode())
                            ->append($this->createOtpInputNode())
                            ->arrayNode('registration_fields')
                                ->info('Registration form fields. String names or arrays with name, type, property, hash, required, mapped, slide_to_confirm.')
                                ->defaultValue(['email', 'password'])
                                ->prototype('variable')->end()
                            ->end()
                            ->append($this->createWebUiNode())
                            ->append($this->createTemplatesNode())
                            ->append($this->createCssNode())
                            ->append($this->createEmbedNode())
                            ->append($this->createPasswordResetNode())
                            ->append($this->createMagicLoginNode())
                            ->append($this->createSocialLoginNode())
                            ->append($this->createQrLoginNode())
                            ->append($this->createRoutesNode())
                            ->scalarNode('firewall')
                                ->info('Symfony firewall name where form_login should point (documented for security.yaml).')
                                ->defaultValue('main')
                            ->end()
                            ->scalarNode('login_success_route')
                                ->info('Route name after successful login. Null uses firewall default_target_path.')
                                ->defaultNull()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('locale')
                    ->info('Auth route localization. Prefer this node over legacy default_locale / enabled_locales / locale_in_path.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('in_path')
                            ->values(['never', 'always', 'both'])
                            ->info('never: /login only. always: /{_locale}/login only. both: register both (see unlocalized).')
                            ->defaultValue('never')
                        ->end()
                        ->scalarNode('default')
                            ->info('Default locale for prefixed routes and bare serve/redirect.')
                            ->defaultValue('en')
                        ->end()
                        ->arrayNode('enabled')
                            ->scalarPrototype()->end()
                            ->defaultValue(['en', 'es'])
                        ->end()
                        ->enumNode('unlocalized')
                            ->values(['serve', 'redirect'])
                            ->info('When in_path=both: serve bare URLs with default locale, or redirect to /{default}/…')
                            ->defaultValue('redirect')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_locale')
                    ->info('Deprecated: use locale.default.')
                    ->defaultValue('en')
                ->end()
                ->arrayNode('enabled_locales')
                    ->info('Deprecated: use locale.enabled.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['en', 'es'])
                ->end()
                ->variableNode('locale_in_path')
                    ->info('Deprecated: use locale.in_path (never|always|both). Bool true/false still accepted.')
                    ->defaultFalse()
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function createRememberMeNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('remember_me'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Persistent login cookie (Symfony firewall remember_me). Set enabled: true or add remember_me to login_fields.')
            ->children()
                ->booleanNode('enabled')
                    ->info('When true, configures firewall remember_me and ensures the login checkbox is shown.')
                    ->defaultFalse()
                ->end()
                ->integerNode('lifetime')
                    ->info('Cookie lifetime in seconds.')
                    ->defaultValue(604800)
                    ->min(60)
                ->end()
                ->scalarNode('path')
                    ->info('Cookie path.')
                    ->defaultValue('/')
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $node;
    }

    private function createPasswordStrengthNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('password_strength'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Optional integration with nowo-tech/password-strength-bundle for registration and password reset fields.')
            ->children()
                ->booleanNode('enabled')
                    ->info('When true, uses PasswordStrengthType on new-password fields if that bundle is installed.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('level')
                    ->info('Policy level passed to PasswordStrengthType and PasswordStrength validator.')
                    ->defaultValue('medium')
                    ->cannotBeEmpty()
                ->end()
                ->enumNode('policy_mode')
                    ->values(['level', 'conditions'])
                    ->defaultValue('level')
                ->end()
            ->end();

        return $node;
    }

    private function createSlideToConfirmNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('slide_to_confirm'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Optional integration with nowo-tech/slide-to-confirm-bundle for registration consent and QR approve.')
            ->children()
                ->booleanNode('enabled')
                    ->info('When true, uses SlideToConfirmType when that bundle is installed and a field/QR option requests it.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('registration_consent')
                    ->info('SlideToConfirm profile used when a registration field sets slide_to_confirm: true (typically gate).')
                    ->defaultValue('gate')
                    ->cannotBeEmpty()
                ->end()
                ->variableNode('qr_login_approve')
                    ->info('SlideToConfirm profile for QR approve (e.g. danger), or false to keep the submit button.')
                    ->defaultFalse()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== false && (!is_string($value) || $value === ''))
                        ->thenInvalid('qr_login_approve must be false or a non-empty SlideToConfirm profile name.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function createDeviceIntelligenceNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('device_intelligence'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Optional integration with nowo-tech/device-intelligence-bundle (PHP 8.3+). Device ID is not a credential.')
            ->children()
                ->booleanNode('enabled')
                    ->info('When true, AuthKit loads collect JS and honours the flags below if the bundle is installed.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('collect_on_auth_pages')
                    ->info('Load device-intelligence.min.js on AuthKit layouts so collect() runs before login/register/QR.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('collect_endpoint')
                    ->info('POST path for Device Intelligence collect (default /_device/collect).')
                    ->defaultValue('/_device/collect')
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('new_device_notify')
                    ->info('After LoginSuccess, set session flag and call NewDeviceLoginNotifierInterface when the cluster is new.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('device_rate_limit')
                    ->info('Extra AuthKitAttemptLimiter consume keyed by device ULID on register / reset / magic request.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('qr_login')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('approve_require_trusted')
                            ->info('When true, QR session_step_up requires an explicit trusted device (not auto-trust on login).')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function createOtpInputNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('otp_input'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Optional integration with nowo-tech/otp-input-bundle for the password-reset code field. UX only; server checks stay mandatory.')
            ->children()
                ->booleanNode('enabled')
                    ->info('When true, uses OtpType when that bundle is installed and password_reset_code is true.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('password_reset_code')
                    ->info('Replace the reset OTP TextType with OtpType (length/charset from password_reset).')
                    ->defaultTrue()
                ->end()
            ->end();

        return $node;
    }

    private function createWebUiNode(): ArrayNodeDefinition
    {
        $node      = (new TreeBuilder('web_ui'))->getRootNode();
        $templates = (new TreeBuilder('templates'))->getRootNode();
        $templates
            ->info('Per-page template overrides (REQ-UI-003 web_ui.templates.<page>): a host template that extends "@!NowoAuthKitBundle/security/<page>.html.twig" and overrides a data block.')
            ->addDefaultsIfNotSet();
        $children = $templates->children();
        foreach (self::PAGE_TEMPLATES as $page => $template) {
            $children->scalarNode($page)->defaultValue($template)->cannotBeEmpty()->end();
        }
        $children->end();

        $node
            ->addDefaultsIfNotSet()
            ->info('Page contract (REQ-UI-003): the auth pages extend @NowoUiKit/page/auth.html.twig on the UiKit guest layout.')
            ->children()
                ->scalarNode('layout_template')
                    ->info('Per-profile layout override; null (default) uses nowo_ui_kit.layouts.guest. The layout must render the UiKit canonical blocks.')
                    ->defaultNull()
                ->end()
                ->append($templates)
                ->arrayNode('form_themes')
                    ->info('Form themes applied to the auth forms (null = UiKit form theme + PasswordToggle widget theme when installed).')
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static fn (string $value): array => [$value])
                    ->end()
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultNull()
                ->end()
                ->scalarNode('brand')
                    ->info('Brand line printed above the panel heading (AuthPage::brand; plain text, null = none).')
                    ->defaultNull()
                ->end()
                ->arrayNode('qr_login')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('stimulus_controller')
                            ->info('Stimulus controller identifier for the QR challenge (data-controller + <id>-*-value / <id>-target attributes). null = the bundled nowo-auth-kit-qr-login.js poller.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || preg_match('/^[a-z][a-z0-9-]*(--[a-z0-9-]+)*$/', $value) !== 1))
                                ->thenInvalid('web_ui.qr_login.stimulus_controller must be a Stimulus identifier (lowercase, dashes), e.g. "qr-login".')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function createTemplatesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('templates'))->getRootNode();
        $children = $node
            ->info('Deprecated since 1.27: use web_ui.layout_template, web_ui.templates.<page> and web_ui.form_themes.')
            ->children();
        $children->scalarNode('layout')
            ->setDeprecated('nowo-tech/auth-kit-bundle', '1.27.0', 'The "%node%" option at "%path%" is deprecated, use "web_ui.layout_template" (null = UiKit guest layout).')
        ->end();
        foreach (array_keys(self::PAGE_TEMPLATES) as $page) {
            $children->scalarNode($page)
                ->setDeprecated('nowo-tech/auth-kit-bundle', '1.27.0', 'The "%node%" option at "%path%" is deprecated, use "web_ui.templates.%node%".')
            ->end();
        }
        $children->arrayNode('form_theme')
            ->setDeprecated('nowo-tech/auth-kit-bundle', '1.27.0', 'The "%node%" option at "%path%" is deprecated, use "web_ui.form_themes".')
            ->beforeNormalization()
                ->ifString()
                ->then(static fn (string $value): array => [$value])
            ->end()
            ->scalarPrototype()->end()
        ->end();
        $children->end();

        return $node;
    }

    private function createCssNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('css'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('Deprecated since 1.27: buttons use the UiKit btn_primary / btn_secondary slots (nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes).')
            ->children()
                ->scalarNode('button_class')
                    ->setDeprecated('nowo-tech/auth-kit-bundle', '1.27.0', 'The "%node%" option at "%path%" is deprecated, use "nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary".')
                    ->defaultValue(self::DEFAULT_BUTTON_CLASS)
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('secondary_button_class')
                    ->setDeprecated('nowo-tech/auth-kit-bundle', '1.27.0', 'The "%node%" option at "%path%" is deprecated, use "nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_secondary".')
                    ->defaultValue(self::DEFAULT_SECONDARY_BUTTON_CLASS)
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Default form themes of the auth forms: the UiKit form theme, then the PasswordToggle widget theme (on UiKit
     * slots) when that bundle is registered ($bundles = kernel.bundles) or, without the kernel bundle list, installed.
     *
     * @param array<string, string>|null $bundles
     *
     * @return list<string>
     */
    public static function defaultFormThemes(?array $bundles = null): array
    {
        $themes = [self::UI_KIT_FORM_THEME];
        if ($bundles !== null ? isset($bundles['NowoPasswordToggleBundle']) : class_exists(self::PASSWORD_TOGGLE_TYPE)) {
            $themes[] = self::AUTH_KIT_PASSWORD_TOGGLE_FORM_THEME;
        }

        return $themes;
    }

    /**
     * Fills web_ui.form_themes (null = {@see self::defaultFormThemes()}) and the legacy templates.form_theme mirror.
     *
     * @param array<string, mixed> $profile
     * @param array<string, string>|null $bundles
     *
     * @return array<string, mixed>
     */
    public static function withFormThemes(array $profile, ?array $bundles = null): array
    {
        /** @var array<string, mixed> $webUi */
        $webUi = $profile['web_ui'];
        $webUi['form_themes'] ??= self::defaultFormThemes($bundles);
        $profile['web_ui']                  = $webUi;
        $profile['templates']['form_theme'] = $webUi['form_themes'];

        return $profile;
    }

    /**
     * Maps the deprecated per-profile templates.* keys onto web_ui.* (after the config blocks are merged; a web_ui
     * value other than the default wins) and rebuilds templates.* as a resolved legacy mirror for 1.x readers
     * (form themes: {@see self::withFormThemes()}, applied by the extension).
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function resolveWebUi(array $profile): array
    {
        /** @var array<string, mixed> $legacy */
        $legacy = is_array($profile['templates'] ?? null) ? $profile['templates'] : [];
        /** @var array{layout_template: ?string, templates: array<string, string>, form_themes: ?list<string>, brand: ?string, qr_login: array{stimulus_controller: ?string}} $webUi */
        $webUi = $profile['web_ui'];

        if (is_string($legacy['layout'] ?? null) && $webUi['layout_template'] === null && $legacy['layout'] !== self::LEGACY_LAYOUT) {
            $webUi['layout_template'] = $legacy['layout'];
        }
        if ($webUi['layout_template'] === self::LEGACY_LAYOUT) {
            $webUi['layout_template'] = null;
        }
        foreach (self::PAGE_TEMPLATES as $page => $default) {
            if (is_string($legacy[$page] ?? null) && $legacy[$page] !== '' && $webUi['templates'][$page] === $default) {
                $webUi['templates'][$page] = $legacy[$page];
            }
        }
        if ($webUi['form_themes'] === null && is_array($legacy['form_theme'] ?? null)) {
            $webUi['form_themes'] = array_values(array_filter($legacy['form_theme'], is_string(...)));
        }

        $profile['web_ui']    = $webUi;
        $profile['templates'] = ['layout' => $webUi['layout_template'] ?? self::LEGACY_LAYOUT]
            + $webUi['templates']
            + ['form_theme' => $webUi['form_themes']];

        return $profile;
    }

    private function createEmbedNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('embed'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->values(array_map(static fn (AuthEmbedMode $mode): string => $mode->value, AuthEmbedMode::cases()))
                    ->info('disabled: full-page routes only. dropdown: embed login/register via auth_kit_dropdown().')
                    ->defaultValue(AuthEmbedMode::Disabled->value)
                ->end()
                ->booleanNode('show_login')
                    ->info('Include the login form in the embedded UI.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('show_register')
                    ->info('Include registration when allowed by registration_mode.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('template')
                    ->defaultValue('@NowoAuthKitBundle/embed/dropdown.html.twig')
                ->end()
                ->scalarNode('login_panel')
                    ->defaultValue('@NowoAuthKitBundle/embed/_login_panel.html.twig')
                ->end()
                ->scalarNode('register_panel')
                    ->defaultValue('@NowoAuthKitBundle/embed/_register_panel.html.twig')
                ->end()
                ->scalarNode('authenticated')
                    ->defaultValue('@NowoAuthKitBundle/embed/_authenticated.html.twig')
                ->end()
            ->end();

        return $node;
    }

    private function createPasswordResetNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('password_reset'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->values(array_map(static fn (PasswordResetMode $mode): string => $mode->value, PasswordResetMode::cases()))
                    ->info('disabled: hide reset flows. enabled: expose request and completion routes.')
                    ->defaultValue(PasswordResetMode::Disabled->value)
                ->end()
                ->enumNode('delivery')
                    ->values(array_map(static fn (PasswordResetDeliveryMode $mode): string => $mode->value, PasswordResetDeliveryMode::cases()))
                    ->info('link: URL token. code: OTP/SMS/email code. both: link URL and code for notifiers.')
                    ->defaultValue(PasswordResetDeliveryMode::Link->value)
                ->end()
                ->integerNode('token_ttl')
                    ->info('Seconds until the reset credential expires.')
                    ->defaultValue(3600)
                    ->min(60)
                ->end()
                ->integerNode('token_bytes')
                    ->info('Entropy for link tokens (bytes before hex encoding).')
                    ->defaultValue(32)
                    ->min(16)
                ->end()
                ->integerNode('code_length')
                    ->defaultValue(6)
                    ->min(4)
                    ->max(12)
                ->end()
                ->enumNode('code_charset')
                    ->values(['numeric', 'alphanumeric'])
                    ->defaultValue('numeric')
                ->end()
                ->integerNode('max_code_attempts')
                    ->info('Failed OTP verifications before the reset credential is cleared (0 = disabled).')
                    ->defaultValue(5)
                    ->min(0)
                    ->max(50)
                ->end()
                ->integerNode('request_rate_limit')
                    ->info('Max password-reset requests per client IP per window (0 = disabled).')
                    ->defaultValue(5)
                    ->min(0)
                ->end()
                ->integerNode('request_rate_window')
                    ->info('Seconds for request_rate_limit window.')
                    ->defaultValue(900)
                    ->min(60)
                ->end()
                ->scalarNode('token_field')
                    ->info('User entity property storing the hashed reset credential.')
                    ->defaultValue('passwordResetToken')
                ->end()
                ->scalarNode('token_expires_field')
                    ->info('User entity property storing credential expiry.')
                    ->defaultValue('passwordResetExpiresAt')
                ->end()
            ->end();

        return $node;
    }

    private function createMagicLoginNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('magic_login'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->values(array_map(static fn (MagicLoginMode $mode): string => $mode->value, MagicLoginMode::cases()))
                    ->info('disabled: hide magic login. enabled: request form + Symfony login_link check route.')
                    ->defaultValue(MagicLoginMode::Disabled->value)
                ->end()
                ->integerNode('lifetime')
                    ->info('Seconds until the magic login link expires (Symfony login_link lifetime).')
                    ->defaultValue(600)
                    ->min(60)
                ->end()
                ->integerNode('max_uses')
                    ->info('How many times the signed login link can be used (Symfony login_link max_uses).')
                    ->defaultValue(1)
                    ->min(1)
                ->end()
                ->integerNode('request_rate_limit')
                    ->info('Max magic-login requests per client IP per window (0 = disabled).')
                    ->defaultValue(5)
                    ->min(0)
                ->end()
                ->integerNode('request_rate_window')
                    ->info('Seconds for request_rate_limit window.')
                    ->defaultValue(900)
                    ->min(60)
                ->end()
                ->booleanNode('confirm_interstitial')
                    ->info('When true, magic_login_check GET renders a CSRF confirm form; POST goes to magic_login_confirm (consume login link after Form CSRF). Pair with login_link.check_post_only.')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }

    private function createSocialLoginNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('social_login'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->values(array_map(static fn (SocialLoginMode $mode): string => $mode->value, SocialLoginMode::cases()))
                    ->info('disabled: hide social login. enabled: OAuth buttons when provider credentials exist in the database.')
                    ->defaultValue(SocialLoginMode::Disabled->value)
                ->end()
                ->booleanNode('create_user_if_missing')
                    ->info('When true, creates a local user from the social profile email if none exists.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('require_verified_email')
                    ->info('When true, linking or creating a local user requires the IdP to assert email_verified (or GitHub verified).')
                    ->defaultTrue()
                ->end()
                ->arrayNode('pkce')
                    ->info('Per-provider PKCE (S256) toggle, keyed by provider (e.g. google: true, my_idp: false). Providers not listed: enabled for built-in google / github / microsoft, disabled for custom providers.')
                    ->useAttributeAsKey('provider')
                    ->normalizeKeys(false)
                    ->booleanPrototype()->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $node;
    }

    private function createQrLoginNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('qr_login'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->values(array_map(static fn (QrLoginMode $mode): string => $mode->value, QrLoginMode::cases()))
                    ->info('disabled: hide QR login. enabled: show QR challenge start link on login page.')
                    ->defaultValue(QrLoginMode::Disabled->value)
                ->end()
                ->integerNode('challenge_ttl')
                    ->info('Seconds until the QR challenge expires (clamped 30–180).')
                    ->defaultValue(90)
                    ->min(30)
                    ->max(180)
                ->end()
                ->integerNode('poll_interval_ms')
                    ->info('Hint for Twig/JS desktop status polling interval (milliseconds).')
                    ->defaultValue(1500)
                    ->min(500)
                ->end()
                ->enumNode('approve_requires')
                    ->values(array_map(static fn (QrLoginApproveMode $mode): string => $mode->value, QrLoginApproveMode::cases()))
                    ->info('session: logged-in phone only. session_step_up: phone session + QrLoginStepUpInterface.')
                    ->defaultValue(QrLoginApproveMode::Session->value)
                ->end()
                ->enumNode('desktop_binding')
                    ->values(array_map(static fn (QrLoginDesktopBinding $mode): string => $mode->value, QrLoginDesktopBinding::cases()))
                    ->info('strict: cookie + IP/UA match. soft: cookie + mismatch event. off: cookie only.')
                    ->defaultValue(QrLoginDesktopBinding::Strict->value)
                ->end()
                ->scalarNode('phone_field')
                    ->info('User entity property for the mobile phone number (PropertyAccessor).')
                    ->defaultValue('phone')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('phone_verified_field')
                    ->info('User entity property for phone verification timestamp (PropertyAccessor).')
                    ->defaultValue('phoneVerifiedAt')
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('create_rate_limit')
                    ->info('Max challenge creations per client IP per window (0 = disabled).')
                    ->defaultValue(5)
                    ->min(0)
                ->end()
                ->integerNode('create_rate_window')
                    ->info('Seconds for create_rate_limit window.')
                    ->defaultValue(600)
                    ->min(60)
                ->end()
                ->integerNode('approve_rate_limit')
                    ->info('Max approve/deny attempts per challenge id (0 = disabled).')
                    ->defaultValue(5)
                    ->min(0)
                ->end()
            ->end();

        return $node;
    }

    private function createRoutesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('routes'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('login')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_login')->end()
                    ->end()
                ->end()
                ->arrayNode('logout')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/logout')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_logout')->end()
                    ->end()
                ->end()
                ->arrayNode('register')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/register')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_register')->end()
                    ->end()
                ->end()
                ->arrayNode('reset_request')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/reset-password')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_reset_password_request')->end()
                    ->end()
                ->end()
                ->arrayNode('reset_password')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/reset-password/reset/{token}')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_reset_password')->end()
                    ->end()
                ->end()
                ->arrayNode('reset_password_code')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/reset-password/complete')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_reset_password_code')->end()
                    ->end()
                ->end()
                ->arrayNode('magic_login_request')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/magic-login')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_magic_login_request')->end()
                    ->end()
                ->end()
                ->arrayNode('magic_login_check')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/magic-login/check')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_magic_login_check')->end()
                    ->end()
                ->end()
                ->arrayNode('magic_login_confirm')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/magic-login/confirm')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_magic_login_confirm')->end()
                    ->end()
                ->end()
                ->arrayNode('social_login_start')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/social/{provider}')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_social_login_start')->end()
                    ->end()
                ->end()
                ->arrayNode('social_login_check')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/social/{provider}/check')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_social_login_check')->end()
                    ->end()
                ->end()
                ->arrayNode('qr_login_start')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/qr')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_qr_login_start')->end()
                    ->end()
                ->end()
                ->arrayNode('qr_login_show')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/qr/{id}')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_qr_login_show')->end()
                    ->end()
                ->end()
                ->arrayNode('qr_login_status')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/qr/{id}/status')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_qr_login_status')->end()
                    ->end()
                ->end()
                ->arrayNode('qr_login_complete')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/qr/{id}/complete')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_qr_login_complete')->end()
                    ->end()
                ->end()
                ->arrayNode('qr_login_approve')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/qr/{id}/approve')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_qr_login_approve')->end()
                    ->end()
                ->end()
                ->arrayNode('qr_login_deny')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/login/qr/{id}/deny')->end()
                        ->scalarNode('name')->defaultValue('nowo_auth_kit_qr_login_deny')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Env placeholders (e.g. %env(DEFAULT_URI)%) and empty values pass here; AbsoluteUrlGenerator validates the resolved value.
     */
    private static function baseUriError(mixed $value): ?string
    {
        if ($value === null || AbsoluteUrlGenerator::isEnvPlaceholder($value)) {
            return null;
        }

        if (!is_string($value)) {
            return 'expected a string or null.';
        }

        $value = trim($value);

        return $value === '' ? null : AbsoluteUrlGenerator::validationError($value);
    }
}
