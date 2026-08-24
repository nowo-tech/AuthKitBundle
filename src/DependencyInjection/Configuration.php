<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Nowo\AuthKitBundle\Enum\AuthEmbedMode;
use Nowo\AuthKitBundle\Enum\MagicLoginMode;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\Enum\PasswordResetMode;
use Nowo\AuthKitBundle\Enum\QrLoginApproveMode;
use Nowo\AuthKitBundle\Enum\QrLoginDesktopBinding;
use Nowo\AuthKitBundle\Enum\QrLoginMode;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Enum\SocialLoginMode;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function trigger_deprecation;

/**
 * Configuration tree for AuthKitBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_auth_kit';

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

                    if (!isset($config['default_profile'])) {
                        $profileNames              = array_keys($config['profiles']);
                        $config['default_profile'] = $profileNames[0] ?? 'default';
                    }

                    $hasExplicitLocale = isset($config['locale']) && is_array($config['locale']);
                    $hasLegacyLocale   = array_key_exists('locale_in_path', $config)
                        || array_key_exists('default_locale', $config)
                        || array_key_exists('enabled_locales', $config);

                    if ($hasExplicitLocale && $hasLegacyLocale) {
                        trigger_deprecation(
                            'nowo-tech/auth-kit-bundle',
                            '1.7.0',
                            'Using "default_locale", "enabled_locales", and/or "locale_in_path" together with "locale" is deprecated; configure only the "locale" node. Nested "locale" values take precedence.',
                        );
                    }

                    $legacyInPath = $config['locale_in_path'] ?? false;
                    if (is_bool($legacyInPath)) {
                        $legacyInPath = $legacyInPath ? 'always' : 'never';
                    } elseif (!is_string($legacyInPath) || !in_array($legacyInPath, ['never', 'always', 'both'], true)) {
                        $legacyInPath = 'never';
                    }

                    if (!isset($config['locale']) || !is_array($config['locale'])) {
                        $config['locale'] = [
                            'in_path'     => $legacyInPath,
                            'default'     => $config['default_locale'] ?? 'en',
                            'enabled'     => $config['enabled_locales'] ?? ['en', 'es'],
                            'unlocalized' => 'redirect',
                        ];
                    } else {
                        $inPath = $config['locale']['in_path'] ?? 'never';
                        if (is_bool($inPath)) {
                            $config['locale']['in_path'] = $inPath ? 'always' : 'never';
                        }
                        $config['locale']['default'] ??= $config['default_locale'] ?? 'en';
                        $config['locale']['enabled'] ??= $config['enabled_locales'] ?? ['en', 'es'];
                        $config['locale']['unlocalized'] ??= 'redirect';
                    }

                    $config['default_locale']  = $config['locale']['default'];
                    $config['enabled_locales'] = $config['locale']['enabled'];
                    $config['locale_in_path']  = ($config['locale']['in_path'] ?? 'never') !== 'never';

                    return $config;
                })
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile name used when no profile is specified explicitly.')
                    ->defaultValue('default')
                ->end()
                ->scalarNode('outbound_mail_ready_checker')
                    ->info('Optional service id implementing OutboundMailReadyCheckerInterface for password-reset and magic-login UI hints.')
                    ->defaultNull()
                ->end()
                ->booleanNode('login_throttle_required')
                    ->info('When true, container compilation fails if nowo-tech/login-throttle-bundle is not registered (production hardening).')
                    ->defaultFalse()
                ->end()
                ->arrayNode('profiles')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->prototype('array')
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

    private function createTemplatesNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('templates'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('layout')
                    ->defaultValue('@NowoAuthKitBundle/layout.html.twig')
                ->end()
                ->scalarNode('login')
                    ->defaultValue('@NowoAuthKitBundle/security/login.html.twig')
                ->end()
                ->scalarNode('register')
                    ->defaultValue('@NowoAuthKitBundle/security/register.html.twig')
                ->end()
                ->scalarNode('reset_request')
                    ->defaultValue('@NowoAuthKitBundle/security/reset_request.html.twig')
                ->end()
                ->scalarNode('reset_password')
                    ->defaultValue('@NowoAuthKitBundle/security/reset_password.html.twig')
                ->end()
                ->scalarNode('reset_password_code')
                    ->defaultValue('@NowoAuthKitBundle/security/reset_password_code.html.twig')
                ->end()
                ->scalarNode('magic_login_request')
                    ->defaultValue('@NowoAuthKitBundle/security/magic_login_request.html.twig')
                ->end()
                ->scalarNode('magic_login_confirm')
                    ->defaultValue('@NowoAuthKitBundle/security/magic_login_confirm.html.twig')
                ->end()
                ->arrayNode('form_theme')
                    ->beforeNormalization()
                        ->ifTrue(static fn (mixed $value): bool => is_string($value))
                        ->then(static fn (string $value): array => [$value])
                    ->end()
                    ->scalarPrototype()->end()
                    ->defaultValue(['@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig'])
                ->end()
            ->end();

        return $node;
    }

    private function createCssNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('css'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('button_class')
                    ->defaultValue('nowo-auth-kit__button')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('secondary_button_class')
                    ->defaultValue('nowo-auth-kit__social-button')
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $node;
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
}
