<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Availability\FeatureAvailability;
use Nowo\AuthKitBundle\Command\PurgeInvitationsCommand;
use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\Config\RememberMeConfigResolver;
use Nowo\AuthKitBundle\Controller\InvitationAcceptController;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\AvailabilityCheckersPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\MultiAccountPass;
use Nowo\AuthKitBundle\Doctrine\EntityManagerRecovery;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessGuard;
use Nowo\AuthKitBundle\Invitation\Security\RoleInvitationsAccessChecker;
use Nowo\AuthKitBundle\Locale\RecipientLocaleResolverInterface;
use Nowo\AuthKitBundle\MagicLogin\AdminMagicLinkIssuer;
use Nowo\AuthKitBundle\Mailer\AlwaysOutboundMailReadyChecker;
use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaAuthenticationSubscriber;
use Nowo\AuthKitBundle\Mfa\EventSubscriber\MfaSessionsSubscriber;
use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Mfa\PersonalData\MfaPersonalDataEraser;
use Nowo\AuthKitBundle\Mfa\PersonalData\MfaPersonalDataExportProvider;
use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\MultiAccount\EventSubscriber\MultiAccountSubscriber;
use Nowo\AuthKitBundle\MultiAccount\PersonalData\AccountSwitcherPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\DormantAccountSwitcherPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\DormantMfaPersonalDataEraser;
use Nowo\AuthKitBundle\PersonalData\SocialAccountPersonalDataEraser;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Security\AuthKitAttemptLimiter;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Twig\AuthKitFlashExtension;
use Nowo\AuthKitBundle\View\AuthPageFactory;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Nowo\UserKitBundle\Sessions\Event\UserSessionsRevokedEvent;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function class_exists;
use function function_exists;
use function in_array;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;
use function trim;

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
        $this->assertInvitations($profiles, $config, $container);

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
        $container->setParameter('nowo_auth_kit.password_policy', $defaultProfile['password_policy']);
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
        // Multi-account routes of the default profile, made public by nowo:auth-kit:configure-security (an anonymous
        // browser still reaches the chooser and switches to a parked account).
        $container->setParameter('nowo_auth_kit.multi_account.public_routes', ($defaultProfile['multi_account']['enabled'] ?? false) === true ? $defaultProfile['multi_account']['routes'] : []);
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
        $container->setParameter('nowo_auth_kit.locale.localized_logout_redirect', $config['locale']['localized_logout_redirect']);
        $container->setParameter('nowo_auth_kit.routing.skip_disabled_features', $config['routing']['skip_disabled_features']);
        $container->setParameter('nowo_auth_kit.invitations.allow_unauthenticated', $this->allowUnauthenticated($profiles));

        $registerMapping = $this->resolveRegisterMapping($config['doctrine']['register_mapping'], $profiles);
        $container->setParameter('nowo_auth_kit.doctrine.register_mapping', $registerMapping);
        if ($registerMapping) {
            // Read by the DoctrineOrmMappingsPass of NowoAuthKitBundle::build() (enabled when the parameter exists).
            $container->setParameter(self::MAPPING_ENABLED_PARAMETER, true);
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
        // 1.42: the pool of the rate-limit / lockout counters (default cache.app, as before).
        $container->getDefinition(AuthKitAttemptLimiter::class)->setArgument('$cache', new Reference((string) $config['attempt_limiter']['cache_pool']));
        // 1.42: the accept page owns the OAuth callback of a social acceptance only where a profile opts in.
        foreach ($profiles as $profile) {
            if (($profile['invitations']['mode'] ?? InvitationsConfiguration::MODE_DISABLED) === InvitationsConfiguration::MODE_ENABLED && ($profile['invitations']['social']['accept_page'] ?? false) === true) {
                $container->getDefinition(InvitationAcceptController::class)->addTag('nowo_auth_kit.social_callback_handler');

                break;
            }
        }

        // 1.43.0: the closed-manager recovery resets only the bundle's own entity managers, never a host one. The named
        // manager must exist and map the AuthKit entities (EntityManagerOptionPass).
        $container->setParameter(EntityManagerOptionPass::PARAMETER, $config['doctrine']['entity_manager']);
        $container->getDefinition(EntityManagerRecovery::class)->setArgument('$entityManagerName', $config['doctrine']['entity_manager']);

        if ($config['doctrine']['table_prefix'] === '') {
            $container->removeDefinition(TablePrefixListener::class);
        }

        $this->registerAvailabilityCheckers($container, $profiles);
        $this->registerInvitations($container, $profiles);
        $this->registerMfa($container, $profiles, $loader, is_array($bundles) ? $bundles : null);
        $this->registerMultiAccount($container, $profiles, $loader);
        $this->registerPersonalDataErasers($container, $registerMapping);
        $container->registerForAutoconfiguration(SocialCallbackHandlerInterface::class)->addTag('nowo_auth_kit.social_callback_handler');

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

    /**
     * Per-profile availability checkers (1.37): a locator keyed `<feature>.<profile>`, none when nothing is configured.
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    private function registerAvailabilityCheckers(ContainerBuilder $container, array $profiles): void
    {
        $checkers = [];
        foreach ($profiles as $name => $profile) {
            foreach ([FeatureAvailability::MAGIC_LOGIN, FeatureAvailability::PASSWORD_RESET] as $feature) {
                $settings = $profile[$feature] ?? null;
                $id       = is_array($settings) ? ($settings['availability_checker'] ?? null) : null;
                if (is_string($id)) {
                    $checkers[$feature . '.' . $name] = new Reference($id);
                }
            }
        }
        if ($checkers === []) {
            return;
        }
        $container->getDefinition(FeatureAvailability::class)->setArgument('$checkers', ServiceLocatorTagPass::register($container, $checkers));
        $container->setParameter(AvailabilityCheckersPass::PARAMETER, array_values(array_map(strval(...), $checkers)));
    }

    /**
     * GY-L5 (1.37): erasure must not depend on a feature being on today. While the entities are mapped and UserKit is
     * installed, a feature that is off gets its dormant eraser, and social links always get theirs.
     */
    private function registerPersonalDataErasers(ContainerBuilder $container, bool $registerMapping): void
    {
        if (!$registerMapping || !interface_exists(PersonalDataEraserInterface::class)) {
            return; // @codeCoverageIgnore
        }
        // Inline: the MFA and switcher services stay absent for hosts without those features.
        $support = static fn (string $class): Definition => (new Definition($class))->setAutowired(true);
        $erasers = [SocialAccountPersonalDataEraser::class => ['$owners' => $support(MfaOwnerResolver::class)]];
        if (!$container->hasDefinition(MfaPersonalDataEraser::class)) {
            $erasers[DormantMfaPersonalDataEraser::class] = ['$store' => $support(MfaStore::class), '$owners' => $support(MfaOwnerResolver::class)];
        }
        if (!$container->hasDefinition(AccountSwitcherPersonalDataEraser::class)) {
            $erasers[DormantAccountSwitcherPersonalDataEraser::class] = ['$store' => $support(AccountVaultStore::class)];
        }
        foreach ($erasers as $eraser => $arguments) {
            $container->register($eraser)
                ->setAutowired(true)
                ->setArguments($arguments)
                ->addTag('nowo_user_kit.personal_data.eraser', ['priority' => SocialAccountPersonalDataEraser::PRIORITY]);
        }
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
            if (($profile['invitations']['mode'] ?? InvitationsConfiguration::MODE_DISABLED) === InvitationsConfiguration::MODE_ENABLED) {
                foreach ($profile['invitations']['routes'] as $routeKey => $route) {
                    if (($profile['invitations']['admin_routes'] ?? true) === false && in_array($routeKey, InvitationsConfiguration::ADMIN_ROUTE_KEYS, true)) {
                        continue;
                    }
                    // 1.42: the social start route exists only with invitations.social.accept_page.
                    if ($routeKey === 'social' && ($profile['invitations']['social']['accept_page'] ?? false) !== true) {
                        continue;
                    }
                    $routes['invitations.' . $routeKey] = $route;
                }
            }
            if (($profile['multi_account']['enabled'] ?? false) === true) {
                foreach ($profile['multi_account']['routes'] as $routeKey => $route) {
                    $routes['multi_account.' . $routeKey] = $route;
                }
            }
            if (($profile['mfa']['enabled'] ?? false) === true) {
                foreach ($profile['mfa']['routes'] as $routeKey => $route) {
                    if ($routeKey === 'admin_reset' && ($profile['mfa']['admin']['routes'] ?? false) !== true) {
                        continue;
                    }
                    $routes['mfa.' . $routeKey] = $route;
                }
            }

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
     * doctrine.register_mapping: true / false / "auto" (mapped only when a profile enables social login, QR login or
     * invitations).
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
            throw new InvalidArgumentException('"nowo_auth_kit.doctrine.register_mapping: false" requires social_login.mode, qr_login.mode and invitations.mode to be "disabled" and mfa.enabled / multi_account.enabled false in every profile (their entities would not be mapped).');
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
            foreach (['social_login', 'qr_login', 'invitations'] as $feature) {
                $settings = $profile[$feature] ?? null;
                if (is_array($settings) && ($settings['mode'] ?? null) === 'enabled') {
                    return true;
                }
            }
            foreach (['mfa', 'multi_account'] as $feature) {
                $settings = $profile[$feature] ?? null;
                if (is_array($settings) && ($settings['enabled'] ?? false) === true) {
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
     * @param array<string, mixed> $profile
     */
    private function isPlainRegistrationField(array $profile, string $field): bool
    {
        foreach (ProfileSettings::fromConfig('_', $profile)->registrationFields as $definition) {
            if ((string) $definition['name'] === $field) {
                return ($definition['type'] ?? 'text') !== 'password' && ($definition['hash'] ?? false) !== true && ($definition['mapped'] ?? true) !== false;
            }
        }

        return false;
    }

    /**
     * Invitations (1.32): `registration_mode: invite_only` needs `invitations.mode: enabled`; an enabled profile needs
     * `absolute_url.base_uri` (the mailed link is never built from the request Host), a registration field for the
     * e-mail, and `security.allow_unauthenticated` as a literal boolean that is true only with kernel.debug.
     *
     * @param array<string, array<string, mixed>> $profiles
     * @param array<string, mixed> $config
     */
    private function assertInvitations(array $profiles, array $config, ContainerBuilder $container): void
    {
        foreach ($profiles as $name => $profile) {
            $invitations = $profile['invitations'];
            $enabled     = $invitations['mode'] === InvitationsConfiguration::MODE_ENABLED;
            if ($profile['registration_mode'] === RegistrationMode::InviteOnly->value && !$enabled) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.registration_mode: invite_only" requires "invitations.mode: enabled" (nobody could register otherwise).', $name));
            }
            if (!$enabled) {
                continue;
            }
            $baseUri = $config['absolute_url']['base_uri'] ?? null;
            if (!is_string($baseUri) || trim($baseUri) === '') {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations" requires "nowo_auth_kit.absolute_url.base_uri": invitation links are never built from the request Host header.', $name));
            }
            if (ProfileSettings::fromConfig($name, $profile)->invitationEmailField() === null) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations" needs a registration field for the invited e-mail (set invitations.email_field to a mapped, non-password registration field).', $name));
            }
            $allow = $invitations['security']['allow_unauthenticated'];
            if (!is_bool($allow)) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations.security.allow_unauthenticated" must be a literal boolean (no env placeholder).', $name));
            }
            if ($allow && (!$container->hasParameter('kernel.debug') || $container->getParameter('kernel.debug') !== true)) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations.security.allow_unauthenticated: true" is a DEV/DEMO setting and is refused when kernel.debug is false.', $name));
            }
            // 1.42: the providers of the accept page start through the profile's social login routes.
            if ($invitations['social']['accept_page'] === true && ($profile['social_login']['mode'] ?? 'disabled') !== 'enabled') {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations.social.accept_page: true" requires "social_login.mode: enabled".', $name));
            }
            // KL-L3: the accept page links the provider to the account it just created; deny would leave it unreachable.
            if ($invitations['social']['accept_page'] === true && ($profile['social_login']['link_existing_account'] ?? 'reset_password') === 'deny') {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations.social.accept_page: true" cannot be used with "social_login.link_existing_account: deny" (the accepted account could not be reached with its provider).', $name));
            }
            $nameField = $invitations['social']['name_field'];
            if ($nameField !== null && !$this->isPlainRegistrationField($profile, (string) $nameField)) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.invitations.social.name_field" must name a mapped, non-password registration field ("%s" is not one).', $name, (string) $nameField));
            }
        }
    }

    /**
     * Several accounts at once (1.34): with at least one `multi_account.enabled` profile, loads `multi_account.yaml`,
     * the sign-in / sign-out listeners on each multi-account firewall's dispatcher, the gate and presenter locators,
     * and records admin-issued magic links (they never enrol, D8). libsodium (D6) and UserKit are required; the
     * firewall data comes from {@see MultiAccountPass}. Nothing is registered otherwise.
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    private function registerMultiAccount(ContainerBuilder $container, array $profiles, YamlFileLoader $loader): void
    {
        $firewalls  = [];
        $gates      = [];
        $presenters = [];
        foreach ($profiles as $name => $profile) {
            $settings = $profile['multi_account'];
            if ($settings['enabled'] !== true) {
                continue;
            }
            if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.multi_account" needs the PHP sodium extension (sealed vault entries).', $name)); // @codeCoverageIgnore
            }
            if ($settings['cookie']['secure'] === false && (!$container->hasParameter('kernel.debug') || $container->getParameter('kernel.debug') !== true)) {
                // DE-I10: without Secure / __Host- the vault cookie travels over HTTP and can be planted from a sibling host.
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.multi_account.cookie.secure: false" is a local HTTP setting and is refused when kernel.debug is false.', $name));
            }
            $firewalls[$profile['firewall']] = true;
            if (is_string($settings['enrolment_gate']) && $settings['enrolment_gate'] !== '') {
                $gates[$name] = new Reference($settings['enrolment_gate']);
            }
            if (is_string($settings['presenter']) && $settings['presenter'] !== '') {
                $presenters[$name] = new Reference($settings['presenter']);
            }
        }
        if ($firewalls === []) {
            return;
        }
        $loader->load('multi_account.yaml');
        if (!interface_exists(PersonalDataEraserInterface::class)) {
            $container->removeDefinition(AccountSwitcherPersonalDataEraser::class); // @codeCoverageIgnore
        }
        $container->setParameter(MultiAccountPass::FIREWALLS_PARAMETER, array_keys($firewalls));
        $vault = $container->getDefinition(AccountVault::class);
        $vault->setArgument('$gates', ServiceLocatorTagPass::register($container, $gates));
        $vault->setArgument('$presenters', ServiceLocatorTagPass::register($container, $presenters));
        $subscriber = $container->getDefinition(MultiAccountSubscriber::class);
        foreach (array_keys($firewalls) as $firewall) {
            $dispatcher = 'security.event_dispatcher.' . $firewall;
            $subscriber
                ->addTag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'dispatcher' => $dispatcher, 'priority' => -512])
                ->addTag('kernel.event_listener', ['event' => LogoutEvent::class, 'method' => 'onLogout', 'dispatcher' => $dispatcher, 'priority' => 32]);
        }
        $container->getDefinition(AdminMagicLinkIssuer::class)->setArgument('$issuedLinks', new Reference('cache.app'));
    }

    /**
     * Native MFA (1.34): with at least one `mfa.enabled` profile, loads `mfa.yaml`, one
     * {@see MfaAuthenticationSubscriber} per MFA firewall (on that firewall's event dispatcher) and the `mfa.policy`
     * locator. A profile needs an `issuer`, and nowo-tech/doctrine-encrypt-bundle unless `require_encryption: false`.
     * Nothing is registered otherwise (defaults unchanged).
     *
     * @param array<string, array<string, mixed>> $profiles
     * @param array<string, string>|null $bundles
     */
    private function registerMfa(ContainerBuilder $container, array $profiles, YamlFileLoader $loader, ?array $bundles): void
    {
        $firewalls = [];
        $policies  = [];
        foreach ($profiles as $name => $profile) {
            $mfa = $profile['mfa'];
            if ($mfa['enabled'] !== true) {
                continue;
            }
            if (!is_string($mfa['issuer']) || trim($mfa['issuer']) === '') {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.mfa.issuer" is required: it is the name authenticator apps show.', $name));
            }
            if ($mfa['require_encryption'] === true && $bundles !== null && !isset($bundles['NowoDoctrineEncryptBundle'])) {
                throw new InvalidArgumentException(sprintf('"nowo_auth_kit.profiles.%s.mfa" stores the TOTP secret with #[Encrypted]: register nowo-tech/doctrine-encrypt-bundle (or set mfa.require_encryption: false for tests and demos only).', $name));
            }
            $firewalls[$profile['firewall']] = true;
            if (is_string($mfa['policy']) && $mfa['policy'] !== '') {
                $policies[$name] = new Reference($mfa['policy']);
            }
        }
        if ($firewalls === []) {
            return;
        }
        $loader->load('mfa.yaml');
        $container->getDefinition(MfaManager::class)->setArgument('$policies', ServiceLocatorTagPass::register($container, $policies));
        foreach (array_keys($firewalls) as $firewall) {
            $dispatcher = 'security.event_dispatcher.' . $firewall;
            $container->register(MfaAuthenticationSubscriber::class . '.' . $firewall, MfaAuthenticationSubscriber::class)
                ->setAutowired(true)
                ->setArgument('$firewall', $firewall)
                ->setArgument('$dispatcher', new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->addTag('kernel.event_listener', ['event' => CheckPassportEvent::class, 'method' => 'onCheckPassport', 'dispatcher' => $dispatcher, 'priority' => -1024])
                ->addTag('kernel.event_listener', ['event' => AuthenticationTokenCreatedEvent::class, 'method' => 'onTokenCreated', 'dispatcher' => $dispatcher, 'priority' => -1024])
                ->addTag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'dispatcher' => $dispatcher, 'priority' => -48]);
        }
        // Without UserKit there are no session events to follow (UserKit is a dev dependency: always installed in tests).
        if (!class_exists(UserSessionsRevokedEvent::class)) {
            $container->removeDefinition(MfaSessionsSubscriber::class); // @codeCoverageIgnore
        }
        if (!interface_exists(PersonalDataEraserInterface::class)) {
            $container->removeDefinition(MfaPersonalDataEraser::class); // @codeCoverageIgnore
            $container->removeDefinition(MfaPersonalDataExportProvider::class); // @codeCoverageIgnore
        }
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return array<string, bool> profile => invitations.security.allow_unauthenticated (enabled profiles only)
     */
    private function allowUnauthenticated(array $profiles): array
    {
        $allow = [];
        foreach ($profiles as $name => $profile) {
            if ($profile['invitations']['mode'] === InvitationsConfiguration::MODE_ENABLED) {
                $allow[$name] = $profile['invitations']['security']['allow_unauthenticated'] === true;
            }
        }

        return $allow;
    }

    /**
     * The per-profile access checkers of the invitation administration (a locator keyed by profile: the configured
     * `access_checker` service, else a {@see RoleInvitationsAccessChecker} on `access_roles`). Without any enabled
     * profile the purge command is not registered.
     *
     * @param array<string, array<string, mixed>> $profiles
     */
    private function registerInvitations(ContainerBuilder $container, array $profiles): void
    {
        $checkers = [];
        foreach ($profiles as $name => $profile) {
            $invitations = $profile['invitations'];
            if ($invitations['mode'] !== InvitationsConfiguration::MODE_ENABLED) {
                continue;
            }
            $custom = $invitations['security']['access_checker'];
            if (is_string($custom) && $custom !== '') {
                $checkers[$name] = new Reference($custom);
                continue;
            }
            $id = 'nowo_auth_kit.invitations.access_checker.' . $name;
            $container->register($id, RoleInvitationsAccessChecker::class)
                ->setArguments([new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE), array_values($invitations['security']['access_roles'])]);
            $checkers[$name] = new Reference($id);
        }

        $container->getDefinition(InvitationsAccessGuard::class)->setArgument('$checkers', ServiceLocatorTagPass::register($container, $checkers));
        $container->registerForAutoconfiguration(InvitationGrantInterface::class)->addTag(InvitationGrantInterface::TAG);
        $container->registerForAutoconfiguration(InvitationActionAuthorizerInterface::class)->addTag(InvitationActionAuthorizerInterface::AUTHORIZER_TAG);

        if ($checkers === []) {
            $container->removeDefinition(PurgeInvitationsCommand::class);
        }
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
