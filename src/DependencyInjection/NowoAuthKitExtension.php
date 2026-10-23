<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\Config\RememberMeConfigResolver;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Locale\RecipientLocaleResolverInterface;
use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\Twig\AuthKitFlashExtension;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function array_key_exists;
use function class_exists;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Loads bundle configuration and registers services.
 */
final class NowoAuthKitExtension extends Extension implements PrependExtensionInterface
{
    /** Present only when the bundle entities are mapped (doctrine.register_mapping). */
    public const MAPPING_ENABLED_PARAMETER = 'nowo_auth_kit.doctrine.mapping_enabled';

    public const BUNDLE_NAME = 'NowoAuthKitBundle';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $defaultProfileName = $config['default_profile'];
        if (!isset($config['profiles'][$defaultProfileName])) {
            throw new InvalidArgumentException(sprintf('The "nowo_auth_kit.default_profile" value "%s" does not match any configured profile.', $defaultProfileName));
        }

        $profiles = $config['profiles'];
        $this->assertUniqueUserClasses($profiles);
        $this->assertUniqueRouteNames($profiles);
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : null;
        /* @var array<string, string>|null $bundles */
        $this->normalizeProfiles($profiles, is_array($bundles) ? $bundles : null);

        $defaultProfile = $profiles[$defaultProfileName];

        $container->setParameter('nowo_auth_kit.default_profile', $defaultProfileName);
        $container->setParameter('nowo_auth_kit.profiles', $profiles);
        $container->setParameter('nowo_auth_kit.user_class', $defaultProfile['user_class']);
        $container->setParameter('nowo_auth_kit.user_identifier_field', $defaultProfile['user_identifier_field']);
        $container->setParameter('nowo_auth_kit.registration_role', $defaultProfile['registration_role']);
        $container->setParameter('nowo_auth_kit.registration_mode', $defaultProfile['registration_mode']);
        $container->setParameter('nowo_auth_kit.login_fields', $defaultProfile['login_fields']);
        $container->setParameter('nowo_auth_kit.remember_me', $defaultProfile['remember_me']);
        $container->setParameter('nowo_auth_kit.password_strength', $defaultProfile['password_strength']);
        $container->setParameter('nowo_auth_kit.slide_to_confirm', $defaultProfile['slide_to_confirm']);
        $container->setParameter('nowo_auth_kit.slide_to_confirm_assets', $this->anySlideToConfirmEnabled($profiles));
        $container->setParameter('nowo_auth_kit.device_intelligence', $defaultProfile['device_intelligence']);
        $container->setParameter('nowo_auth_kit.device_intelligence_assets', $this->anyDeviceIntelligenceAssets($profiles));
        $container->setParameter(
            'nowo_auth_kit.device_intelligence_collect_endpoint',
            $defaultProfile['device_intelligence']['collect_endpoint'],
        );
        $container->setParameter('nowo_auth_kit.otp_input', $defaultProfile['otp_input']);
        $container->setParameter('nowo_auth_kit.otp_input_assets', $this->anyOtpInputAssets($profiles));
        $container->setParameter(
            'nowo_auth_kit.password_toggle_assets',
            is_array($bundles) ? isset($bundles['NowoPasswordToggleBundle']) : class_exists(Configuration::PASSWORD_TOGGLE_TYPE),
        );
        $container->setParameter('nowo_auth_kit.registration_fields', $defaultProfile['registration_fields']);
        $container->setParameter('nowo_auth_kit.templates', $defaultProfile['templates']);
        $container->setParameter('nowo_auth_kit.css', $defaultProfile['css']);
        $container->setParameter('nowo_auth_kit.embed', $defaultProfile['embed']);
        $container->setParameter('nowo_auth_kit.routes', $defaultProfile['routes']);
        $container->setParameter('nowo_auth_kit.password_reset', $defaultProfile['password_reset']);
        $container->setParameter('nowo_auth_kit.password_reset.mode', $defaultProfile['password_reset']['mode']);
        $container->setParameter('nowo_auth_kit.password_reset.delivery', $defaultProfile['password_reset']['delivery']);
        $container->setParameter('nowo_auth_kit.password_reset.token_ttl', $defaultProfile['password_reset']['token_ttl']);
        $container->setParameter('nowo_auth_kit.password_reset.token_bytes', $defaultProfile['password_reset']['token_bytes']);
        $container->setParameter('nowo_auth_kit.password_reset.code_length', $defaultProfile['password_reset']['code_length']);
        $container->setParameter('nowo_auth_kit.password_reset.code_charset', $defaultProfile['password_reset']['code_charset']);
        $container->setParameter('nowo_auth_kit.password_reset.token_field', $defaultProfile['password_reset']['token_field']);
        $container->setParameter('nowo_auth_kit.password_reset.token_expires_field', $defaultProfile['password_reset']['token_expires_field']);
        $container->setParameter('nowo_auth_kit.magic_login', $defaultProfile['magic_login']);
        $container->setParameter('nowo_auth_kit.magic_login.mode', $defaultProfile['magic_login']['mode']);
        $container->setParameter('nowo_auth_kit.magic_login.lifetime', $defaultProfile['magic_login']['lifetime']);
        $container->setParameter('nowo_auth_kit.magic_login.max_uses', $defaultProfile['magic_login']['max_uses']);
        $container->setParameter('nowo_auth_kit.magic_login.admin_link.lifetime', $defaultProfile['magic_login']['admin_link']['lifetime']);
        $container->setParameter('nowo_auth_kit.magic_login.admin_link.protected_roles', $defaultProfile['magic_login']['admin_link']['protected_roles']);
        $container->setParameter('nowo_auth_kit.social_login', $defaultProfile['social_login']);
        $container->setParameter('nowo_auth_kit.social_login.mode', $defaultProfile['social_login']['mode']);
        $container->setParameter('nowo_auth_kit.social_login.create_user_if_missing', $defaultProfile['social_login']['create_user_if_missing']);
        $container->setParameter('nowo_auth_kit.social_login.require_verified_email', $defaultProfile['social_login']['require_verified_email']);
        $container->setParameter('nowo_auth_kit.qr_login', $defaultProfile['qr_login']);
        $container->setParameter('nowo_auth_kit.qr_login.mode', $defaultProfile['qr_login']['mode']);
        $container->setParameter('nowo_auth_kit.firewall', $defaultProfile['firewall']);
        $container->setParameter('nowo_auth_kit.login_success_route', $defaultProfile['login_success_route']);
        $container->setParameter('nowo_auth_kit.locale', $config['locale']);
        $container->setParameter('nowo_auth_kit.locale.in_path', $config['locale']['in_path']);
        $container->setParameter('nowo_auth_kit.locale.default', $config['locale']['default']);
        $container->setParameter('nowo_auth_kit.locale.enabled', $config['locale']['enabled']);
        $container->setParameter('nowo_auth_kit.locale.unlocalized', $config['locale']['unlocalized']);
        $container->setParameter('nowo_auth_kit.default_locale', $config['locale']['default']);
        $container->setParameter('nowo_auth_kit.enabled_locales', $config['locale']['enabled']);
        $container->setParameter('nowo_auth_kit.locale_in_path', $config['locale']['in_path']);
        $container->setParameter('nowo_auth_kit.outbound_mail_ready_checker', $config['outbound_mail_ready_checker']);
        $container->setParameter('nowo_auth_kit.login_throttle_required', $config['login_throttle_required']);
        $container->setParameter('nowo_auth_kit.flash.translate', $config['flash']['translate']);
        $container->setParameter('nowo_auth_kit.flash.twig_filter_domains', array_values($config['flash']['twig_filter_domains']));
        $container->setParameter('nowo_auth_kit.recipient_locale.enabled', $config['recipient_locale']['enabled']);
        $container->setParameter('nowo_auth_kit.create_admin.min_password_length', $config['create_admin']['min_password_length']);
        $container->setParameter('nowo_auth_kit.create_admin.roles', array_values($config['create_admin']['roles']));
        $container->setParameter('nowo_auth_kit.absolute_url.base_uri', $config['absolute_url']['base_uri']);
        $container->setParameter('nowo_auth_kit.http_client.timeout', (float) $config['http_client']['timeout']);
        $container->setParameter('nowo_auth_kit.http_client.max_duration', (float) $config['http_client']['max_duration']);
        $container->setParameter('nowo_auth_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_auth_kit.locale.unlocalized_route_suffix', $config['locale']['unlocalized_route_suffix']);
        $container->setParameter('nowo_auth_kit.routing.skip_disabled_features', $config['routing']['skip_disabled_features']);

        $registerMapping = $this->resolveRegisterMapping($config['doctrine']['register_mapping'], $profiles);
        $container->setParameter('nowo_auth_kit.doctrine.register_mapping', $registerMapping);
        if ($registerMapping) {
            // Read by the DoctrineOrmMappingsPass of NowoAuthKitBundle::build() (enabled when the parameter exists).
            $container->setParameter(self::MAPPING_ENABLED_PARAMETER, true);
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if ($config['doctrine']['table_prefix'] === '') {
            $container->removeDefinition(TablePrefixListener::class);
        }

        if ($config['flash']['twig_filter'] !== true) {
            $container->removeDefinition(AuthKitFlashExtension::class);
        }

        $resolverServiceId = $config['recipient_locale']['resolver'];
        if (is_string($resolverServiceId) && $resolverServiceId !== '') {
            $container->setAlias(RecipientLocaleResolverInterface::class, $resolverServiceId);
        }

        $checkerServiceId = $config['outbound_mail_ready_checker'];
        if (is_string($checkerServiceId) && $checkerServiceId !== '') {
            $container->setAlias(OutboundMailReadyCheckerInterface::class, $checkerServiceId);

            return;
        }

        $container->setAlias(OutboundMailReadyCheckerInterface::class, AlwaysOutboundMailReadyChecker::class);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        $this->prependUiKitThemeBridge($container);
        $this->prependDoctrineMappingOptOut($container);
        if (!$container->hasExtension('framework')) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    Configuration::ALIAS => [
                        'base_path' => '/bundles/nowoauthkit',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     * @param array<string, string>|null $bundles kernel.bundles
     */
    private function normalizeProfiles(array &$profiles, ?array $bundles = null): void
    {
        foreach ($profiles as $name => &$profile) {
            $profile = Configuration::withFormThemes($profile, $bundles);

            if ('' === ($profile['user_class'] ?? '')) {
                throw new InvalidArgumentException(sprintf('The "nowo_auth_kit.profiles.%s.user_class" configuration value is required.', $name));
            }

            $loginFields = RememberMeConfigResolver::ensureLoginField(
                $profile['login_fields'],
                (bool) $profile['remember_me']['enabled'],
            );

            $profile['login_fields'] = FieldConfigNormalizer::normalizeLoginFields(
                $loginFields,
                $profile['user_identifier_field'],
            );
            $profile['registration_fields'] = FieldConfigNormalizer::normalizeRegistrationFields(
                $profile['registration_fields'],
            );
        }
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function assertUniqueUserClasses(array $profiles): void
    {
        $seen = [];

        foreach ($profiles as $name => $profile) {
            $userClass = $profile['user_class'] ?? '';
            if ($userClass === '') {
                continue;
            }

            if (isset($seen[$userClass])) {
                throw new InvalidArgumentException(sprintf('Duplicate user_class "%s" in profiles "%s" and "%s".', $userClass, $seen[$userClass], $name));
            }

            $seen[$userClass] = $name;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function assertUniqueRouteNames(array $profiles): void
    {
        $seen = [];

        foreach ($profiles as $profileName => $profile) {
            /** @var array<string, array{path: string, name: string, methods?: list<string>}> $routes */
            $routes = $profile['routes'];

            foreach ($routes as $routeKey => $route) {
                $routeName = $route['name'];

                if (isset($seen[$routeName])) {
                    throw new InvalidArgumentException(sprintf('Duplicate route name "%s" in profiles "%s" and "%s" (route key "%s").', $routeName, $seen[$routeName]['profile'], $profileName, $routeKey));
                }

                $seen[$routeName] = ['profile' => $profileName, 'key' => $routeKey];
            }
        }
    }

    /**
     * When FormKit is installed, register the {@code auth_kit} profile. Forms select it via {@code #[FormKitConfig]}.
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('auth_kit', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            // REQ-UI-003: no framework classes here — the UiKit form theme (web_ui.form_themes) paints inputs and
            // checkboxes through the input / check / check_input theme slots for the host's css_framework.
            $seed['profiles'] = [
                'auth_kit' => [
                    'alias'              => 'auth_kit',
                    'translation_domain' => 'NowoAuthKitBundle',
                    'auto_placeholder'   => false,
                    'auto_help'          => false,
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * Deprecated css.button_class / css.secondary_button_class (any profile; first non-default value wins) →
     * nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary / btn_secondary, prepended so a host's own
     * theme exception still wins (REQ-UI-003 chrome bridge, removed in 2.0).
     */
    private function prependUiKitThemeBridge(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $map     = ['button_class' => ['btn_primary', Configuration::DEFAULT_BUTTON_CLASS], 'secondary_button_class' => ['btn_secondary', Configuration::DEFAULT_SECONDARY_BUTTON_CLASS]];
        $classes = [];
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            /** @var array<string, mixed> $config */
            $blocks = [$config['css'] ?? null];
            foreach (is_array($config['profiles'] ?? null) ? $config['profiles'] : [] as $profile) {
                $blocks[] = is_array($profile) ? ($profile['css'] ?? null) : null;
            }
            foreach ($blocks as $css) {
                foreach ($map as $key => [$slot, $default]) {
                    $value = is_array($css) ? ($css[$key] ?? null) : null;
                    if (is_string($value) && $value !== '' && $value !== $default && !isset($classes[$slot])) {
                        $classes[$slot] = $value;
                    }
                }
            }
        }

        if ($classes !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', ['theme' => ['bundles' => [AuthPageFactory::BUNDLE => ['classes' => $classes]]]]);
        }
    }

    /**
     * doctrine.register_mapping: true / false / "auto" (mapped only when a profile enables social or QR login).
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    private function resolveRegisterMapping(bool|string $registerMapping, array $profiles): bool
    {
        $needed = $this->profilesNeedEntities($profiles);

        if ($registerMapping === Configuration::REGISTER_MAPPING_AUTO) {
            return $needed;
        }

        if ($registerMapping === false && $needed) {
            throw new InvalidArgumentException('"nowo_auth_kit.doctrine.register_mapping: false" requires social_login.mode and qr_login.mode to be "disabled" in every profile (their entities would not be mapped).');
        }

        return (bool) $registerMapping;
    }

    /**
     * @param array<array-key, mixed> $profiles
     */
    private function profilesNeedEntities(array $profiles): bool
    {
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            foreach (['social_login', 'qr_login'] as $feature) {
                $settings = $profile[$feature] ?? null;
                if (is_array($settings) && ($settings['mode'] ?? null) === 'enabled') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Doctrine auto_mapping would map src/Entity of every bundle: when doctrine.register_mapping resolves to false,
     * prepend `mappings.NowoAuthKitBundle.mapping: false` on the auto-mapped entity manager (unless the host already
     * configures a NowoAuthKitBundle mapping).
     */
    private function prependDoctrineMappingOptOut(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }

        $registerMapping = true;
        $profiles        = [];
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            /** @var array<string, mixed> $config */
            $doctrine = $config['doctrine'] ?? null;
            if (is_array($doctrine) && array_key_exists('register_mapping', $doctrine)) {
                $registerMapping = $doctrine['register_mapping'];
            }
            $profiles[] = $config;
            foreach (is_array($config['profiles'] ?? null) ? $config['profiles'] : [] as $profile) {
                $profiles[] = $profile;
            }
        }

        $mapped = $registerMapping === Configuration::REGISTER_MAPPING_AUTO
            ? $this->profilesNeedEntities($profiles)
            : $registerMapping !== false;
        if ($mapped) {
            return;
        }

        $manager = null;
        $hasOrm  = false;
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            /** @var array<string, mixed> $config */
            if (!is_array($config['orm'] ?? null)) {
                continue;
            }
            $hasOrm = true;
            $orm    = $config['orm'];
            if (is_array($orm['mappings'] ?? null) && array_key_exists(self::BUNDLE_NAME, $orm['mappings'])) {
                return;
            }
            if (is_string($orm['default_entity_manager'] ?? null)) {
                $manager ??= $orm['default_entity_manager'];
            }
            foreach (is_array($orm['entity_managers'] ?? null) ? $orm['entity_managers'] : [] as $name => $entityManager) {
                if (!is_array($entityManager)) {
                    continue;
                }
                if (is_array($entityManager['mappings'] ?? null) && array_key_exists(self::BUNDLE_NAME, $entityManager['mappings'])) {
                    return;
                }
                if (($entityManager['auto_mapping'] ?? false) === true) {
                    $manager = (string) $name;
                }
            }
        }

        if (!$hasOrm) {
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => ['entity_managers' => [$manager ?? 'default' => ['mappings' => [self::BUNDLE_NAME => ['mapping' => false]]]]],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function anySlideToConfirmEnabled(array $profiles): bool
    {
        foreach ($profiles as $profile) {
            if (($profile['slide_to_confirm']['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function anyDeviceIntelligenceAssets(array $profiles): bool
    {
        foreach ($profiles as $profile) {
            $config = $profile['device_intelligence'] ?? [];
            if (($config['enabled'] ?? false) === true && ($config['collect_on_auth_pages'] ?? true) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function anyOtpInputAssets(array $profiles): bool
    {
        foreach ($profiles as $profile) {
            $config = $profile['otp_input'] ?? [];
            if (($config['enabled'] ?? false) === true && ($config['password_reset_code'] ?? true) === true) {
                return true;
            }
        }

        return false;
    }
}
