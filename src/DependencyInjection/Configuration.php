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
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\Routing\AbsoluteUrlGenerator;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\SocialLogin\Oidc\PostLogoutRedirectUri;
use Nowo\AuthKitBundle\SocialLogin\ProviderMarkResolver;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function class_exists;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_string;
use function json_encode;
use function preg_match;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Configuration tree for AuthKitBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_auth_kit';

    /**
     * The 1.x layout `@NowoAuthKitBundle/layout.html.twig` (a bridge to the UiKit guest layout from 1.27). 2.0: the
     * template is removed; as `web_ui.layout_template` it still means null (the UiKit guest layout), and it is the value
     * of the read-only `templates.layout` mirror and of {@see ProfileSettings::legacyLayoutTemplate()} without an
     * override, so a 1.x host page template that extends `layout_template` fails at render ("unable to find").
     */
    public const LEGACY_LAYOUT = '@NowoAuthKitBundle/layout.html.twig';

    /** 2.0: the build error of the removed root locale keys (deprecated since 1.7 / 1.54). */
    public const REMOVED_LOCALE_KEYS = 'The AuthKit option(s) %s were removed in 2.0 (deprecated since 1.54; together with "locale" since 1.7): use "nowo_auth_kit.locale.default", "nowo_auth_kit.locale.enabled" and "nowo_auth_kit.locale.in_path" instead. See UPGRADING.md, "To 2.0.0".';

    /** 2.0: the build error of the removed per-profile templates.* keys (deprecated since 1.27). */
    public const REMOVED_TEMPLATES_KEY = 'The AuthKit option "templates" (templates.layout, templates.<page>, templates.form_theme) was removed in 2.0 (deprecated since 1.27): use "web_ui.layout_template" (null = nowo_ui_kit.layouts.guest), "web_ui.templates.<page>" and "web_ui.form_themes" instead. See UPGRADING.md, "To 2.0.0".';

    /** 2.0: the build error of the removed per-profile css.* keys (deprecated since 1.27). */
    public const REMOVED_CSS_KEY = 'The AuthKit option "css" (css.button_class, css.secondary_button_class) was removed in 2.0 (deprecated since 1.27): set "nowo_ui_kit.theme.bundles.NowoAuthKitBundle.classes.btn_primary" / "btn_secondary" instead. See UPGRADING.md, "To 2.0.0".';

    public const UI_KIT_FORM_THEME = '@NowoUiKit/form/nowo_ui_layout.html.twig';

    public const PASSWORD_TOGGLE_FORM_THEME = '@NowoPasswordToggleBundle/Form/toggle_password_widget.html.twig';

    /** PasswordToggle widget theme with the UiKit `input` slot on the password input (uses PASSWORD_TOGGLE_FORM_THEME). */
    public const AUTH_KIT_PASSWORD_TOGGLE_FORM_THEME = '@NowoAuthKitBundle/form/password_toggle_widget.html.twig';

    public const PASSWORD_TOGGLE_TYPE = 'Nowo\\PasswordToggleBundle\\Form\\Type\\PasswordType';

    public const DEFAULT_BUTTON_CLASS = 'nowo-auth-kit__button';

    public const DEFAULT_SECONDARY_BUTTON_CLASS = 'nowo-auth-kit__social-button';

    public const DEFAULT_UNLOCALIZED_ROUTE_SUFFIX = '_unlocalized';

    public const DEFAULT_PASSWORD_MIN_LENGTH = 12;

    /** `doctrine.mapping_type` values (1.46.0). */
    public const MAPPING_TYPE_ATTRIBUTE = 'attribute';

    public const MAPPING_TYPE_XML = 'xml';

    public const REGISTER_MAPPING_AUTO = 'auto';

    /** password_reset.storage (1.46.0): the reset credential on the user entity (default, earlier behaviour). */
    public const PASSWORD_RESET_STORAGE_USER_ENTITY = 'user_entity';

    /** password_reset.storage (1.46.0): the reset credential in the bundle-owned auth_kit_password_reset table. */
    public const PASSWORD_RESET_STORAGE_TABLE = 'table';

    /** @var list<string> */
    public const LOGOUT_METHODS = ['GET', 'POST'];

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
        'invitation_accept'   => '@NowoAuthKitBundle/security/invitation_accept.html.twig',
        'invitation_invalid'  => '@NowoAuthKitBundle/security/invitation_invalid.html.twig',
        'mfa_challenge'       => '@NowoAuthKitBundle/mfa/challenge.html.twig',
        'mfa_setup'           => '@NowoAuthKitBundle/mfa/setup.html.twig',
        'mfa_recovery_codes'  => '@NowoAuthKitBundle/mfa/recovery_codes.html.twig',
        'accounts_chooser'    => '@NowoAuthKitBundle/security/accounts_chooser.html.twig',
        'mfa_account'         => '@NowoAuthKitBundle/mfa/account.html.twig',
        'mfa_account_codes'   => '@NowoAuthKitBundle/mfa/account_codes.html.twig',
        'webauthn_account'    => '@NowoAuthKitBundle/webauthn/account.html.twig',
    ];

    /**
     * Default `login_target.denied_path_prefixes` (1.44): well-known machine endpoints.
     *
     * @var list<string>
     */
    public const DEFAULT_DENIED_PATH_PREFIXES = [
        '/.well-known',
        '/health',
        '/healthz',
        '/metrics',
        '/otlp',
        '/ingest',
        '/mcp',
        '/build',
        '/bundles',
        '/assets',
    ];

    /**
     * Default `login_target.denied_path_patterns` (1.44): an `api` segment anywhere (`/api/…`, `/en/api/…`,
     * `/chat/api/…`), a segment starting with `_` (`/_wdt`, `/_profiler`, `/_fragment`, `/_components`), a `logout`
     * segment, and data or asset file extensions.
     *
     * @var list<string>
     */
    public const DEFAULT_DENIED_PATH_PATTERNS = [
        '#(^|/)api(/|$)#i',
        '#(^|/)_#',
        '#(^|/)logout(/|$)#i',
        '#\.(json|jsonld|ndjson|xml|txt|csv|tsv|ics|rss|atom|webmanifest|js|mjs|css|map|zip|gz|pdf|png|jpe?g|gif|svg|webp|avif|ico|ya?ml|md|woff2?|ttf|wasm)$#i',
    ];

    /**
     * Default `login_target.denied_route_patterns` (1.44): logout routes.
     *
     * @var list<string>
     */
    public const DEFAULT_DENIED_ROUTE_PATTERNS = [
        '/(^|_)logout(_|$)/',
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
        'password_policy',
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
        'invitations',
        'mfa',
        'multi_account',
        'webauthn',
        'saml',
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
                    // Derived keys (default_profile) are filled after the merge (validate()).
                    // 2.0: the root keys deprecated in 1.7 / 1.54 are removed; name their replacements.
                    $removedLocaleKeys = array_values(array_intersect(['default_locale', 'enabled_locales', 'locale_in_path'], array_keys($config)));
                    if ($removedLocaleKeys !== []) {
                        throw new InvalidConfigurationException(sprintf(self::REMOVED_LOCALE_KEYS, '"nowo_auth_kit.' . implode('", "nowo_auth_kit.', $removedLocaleKeys) . '"'));
                    }
                    if (isset($config['locale']) && is_array($config['locale']) && is_bool($config['locale']['in_path'] ?? null)) {
                        $config['locale']['in_path'] = $config['locale']['in_path'] ? 'always' : 'never';
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

                    return $config;
                })
            ->end()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile name used when no profile is specified explicitly (default: first profile).')
                    ->defaultNull()
                ->end()
                ->scalarNode('identifier_normalizer')
                    ->info('1.49.0: normaliser applied to every identifier AuthKit looks a user up by (and stores). null / "exact": refuse unusable values, change nothing else (default). "lowercase_ascii_email": also lower-case ASCII letters of e-mail addresses (needs lower-case stored identifiers; see UPGRADING). Else the id of a service implementing Identity\\UserIdentifierNormalizerInterface.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || trim($value) === ''))
                        ->thenInvalid('"identifier_normalizer" must be null, "exact", "lowercase_ascii_email" or a service id.')
                    ->end()
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
                ->arrayNode('http_client')
                    ->info('Deadlines of the outbound OAuth2 / OIDC calls (token + userinfo) made by social login (REQ-RUNTIME-001). Keep them below PHP max_execution_time.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('timeout')
                            ->info('Idle timeout in seconds (no data received) for each token / userinfo request.')
                            ->defaultValue(OAuth2Client::DEFAULT_TIMEOUT)
                            ->min(0.1)
                        ->end()
                        ->floatNode('max_duration')
                            ->info('Total time limit in seconds for each token / userinfo request (must be >= timeout).')
                            ->defaultValue(OAuth2Client::DEFAULT_MAX_DURATION)
                            ->min(0.1)
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $http): bool => $http['max_duration'] < $http['timeout'])
                        ->then(static function (array $http): never {
                            throw new InvalidArgumentException(sprintf('Invalid "nowo_auth_kit.http_client": max_duration (%s) must be greater than or equal to timeout (%s).', $http['max_duration'], $http['timeout']));
                        })
                    ->end()
                ->end()
                ->arrayNode('oidc')
                    ->info('OpenID Connect providers (2.3.0: generic issuer, gitlab, apple, linkedin, slack): ID token checks and the limits of the discovery / JWKS / token / userinfo fetches (always HTTPS to public addresses, no redirects).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('allowed_algorithms')
                            ->info('ID token signature algorithms accepted. A non-empty subset of RS256, RS384, RS512, ES256, ES384, ES512; "none" and HS* are never accepted.')
                            ->scalarPrototype()->end()
                            ->defaultValue(OidcOptions::SUPPORTED_ALGORITHMS)
                            ->validate()
                                ->ifTrue(static fn (array $algorithms): bool => $algorithms === [] || array_diff($algorithms, OidcOptions::SUPPORTED_ALGORITHMS) !== [])
                                ->then(static function (array $algorithms): never {
                                    throw new InvalidArgumentException(sprintf('Invalid "nowo_auth_kit.oidc.allowed_algorithms": a non-empty subset of %s ("none" and HS* are never accepted); got [%s].', implode(', ', OidcOptions::SUPPORTED_ALGORITHMS), implode(', ', array_map('strval', $algorithms))));
                                })
                            ->end()
                        ->end()
                        ->integerNode('clock_skew')
                            ->info('Leeway in seconds for exp / iat / nbf.')
                            ->defaultValue(OidcOptions::DEFAULT_CLOCK_SKEW)
                            ->min(0)->max(300)
                        ->end()
                        ->integerNode('max_id_token_age')
                            ->info('Oldest accepted ID token issue time (iat), in seconds.')
                            ->defaultValue(OidcOptions::DEFAULT_MAX_ID_TOKEN_AGE)
                            ->min(60)->max(86400)
                        ->end()
                        ->integerNode('metadata_cache_ttl')
                            ->info('Seconds a discovery document stays in the cache pool.')
                            ->defaultValue(OidcOptions::DEFAULT_METADATA_CACHE_TTL)
                            ->min(60)->max(604800)
                        ->end()
                        ->integerNode('jwks_cache_ttl')
                            ->info('Seconds a key set (JWKS) stays in the cache pool.')
                            ->defaultValue(OidcOptions::DEFAULT_JWKS_CACHE_TTL)
                            ->min(60)->max(604800)
                        ->end()
                        ->integerNode('jwks_refresh_interval')
                            ->info('Minimum seconds between two refetches of a key set triggered by an unknown key id (key rotation).')
                            ->defaultValue(OidcOptions::DEFAULT_JWKS_REFRESH_INTERVAL)
                            ->min(0)->max(3600)
                        ->end()
                        ->integerNode('discovery_failure_ttl')
                            ->info('Seconds a failed discovery is remembered per issuer (negative cache) and not retried; 0 retries at once.')
                            ->defaultValue(OidcOptions::DEFAULT_DISCOVERY_FAILURE_TTL)
                            ->min(0)->max(300)
                        ->end()
                        ->integerNode('max_response_bytes')
                            ->info('Largest discovery / JWKS / token / userinfo response accepted, in bytes (enforced while downloading).')
                            ->defaultValue(OidcOptions::DEFAULT_MAX_RESPONSE_BYTES)
                            ->min(4096)->max(5242880)
                        ->end()
                        ->arrayNode('trusted_private_hosts')
                            ->info('2.9.0, TEST ENVIRONMENT ONLY (refused at compile time unless kernel.environment is "test"): exact host names (no wildcard, no IP, case-insensitive) whose private or loopback addresses the OIDC fetches accept, e.g. a simulated identity provider container in end-to-end tests. Cloud metadata addresses stay refused; HTTPS stays required.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->validate()
                                ->always(static function (array $hosts): array {
                                    foreach ($hosts as $host) {
                                        if (($error = OidcOptions::trustedPrivateHostError($host)) !== null) {
                                            throw new InvalidArgumentException($error);
                                        }
                                    }

                                    return array_values(array_unique(array_map(static fn (string $host): string => strtolower($host), $hosts)));
                                })
                            ->end()
                        ->end()
                        ->scalarNode('cache_pool')
                            ->info('Cache pool service (Symfony\\Contracts\\Cache\\CacheInterface) holding discovery documents and key sets. Shared between workers and processes; never a service property.')
                            ->defaultValue('cache.app')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Doctrine mapping of the bundle-owned tables (QR login challenges, social login credentials / accounts).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_prefix')
                            ->info('Prefix prepended to every AuthKit table name (e.g. "app_" yields app_auth_kit_qr_login_challenge). Empty: unprefixed names.')
                            ->defaultValue('')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[A-Za-z0-9_]*$/', $value) !== 1)
                                ->thenInvalid('Invalid "nowo_auth_kit.doctrine.table_prefix" %s: use letters, digits and underscores only.')
                            ->end()
                        ->end()
                        ->scalarNode('entity_manager')
                            ->info('Since 1.43.0: name of an additional entity manager that maps the AuthKit entities (the host maps them there itself). After a failed write the bundle resets only its own managers: the one the write went through, the manager of the AuthKit entities (ManagerRegistry::getManagerForClass(), always included) and this one. The private entity manager (2.0: the only mode) is still created on the default manager, and this one stays in the recovery set. The build fails when the manager does not exist or does not map Nowo\\AuthKitBundle\\Entity.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                                ->thenInvalid('Invalid "nowo_auth_kit.doctrine.entity_manager" %s: use an entity manager name or null.')
                            ->end()
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->info('2.0: no effect. With the AuthKit entities mapped, AuthKit always reads and writes them through a private entity manager created on the connection and configuration of the default manager (the tables do not move), so an AuthKit write never flushes the application\'s pending changes; the account writes (the application\'s user entities) still go through the application\'s manager. false fails the build; true is accepted until 3.0 (deprecated) and is still refused without the AuthKit entities mapped.')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/auth-kit-bundle', '2.0', 'The "%path%.%node%" option has no effect since 2.0 (the private entity manager is the only mode); remove it.')
                        ->end()
                        ->enumNode('mapping_type')
                            ->values([self::MAPPING_TYPE_ATTRIBUTE, self::MAPPING_TYPE_XML])
                            ->info('Since 1.46.0: attribute (default) or xml (src/Resources/config/doctrine-xml/*.orm.xml: the same tables, columns, indexes and constraints) of the mapping the bundle registers on the default manager when register_mapping resolves to true (it also replaces the attribute driver an auto_mapping default manager would add for Nowo\\AuthKitBundle\\Entity). A manager named by doctrine.entity_manager keeps the mapping the host gives it.')
                            ->defaultValue(self::MAPPING_TYPE_ATTRIBUTE)
                        ->end()
                        ->booleanNode('require_enterprise_secret_encryption')
                            ->info('2.8.0 (fail closed): true refuses to insert or update an enterprise credential (enterprise_sso) holding a client secret unless nowo-tech/doctrine-encrypt-bundle encrypts on the entity manager that writes it (EnterpriseSecretNotEncryptedException, nothing written). Set false only for tests and demos.')
                            ->defaultTrue()
                        ->end()
                        ->enumNode('register_mapping')
                            ->values([true, false, self::REGISTER_MAPPING_AUTO])
                            ->info('Map the bundle-owned entities (auth_kit_* tables). true: always (default). false: never (social and QR login must stay disabled; also opts the bundle out of Doctrine auto_mapping). auto: only when a profile enables social_login or qr_login.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('routing')
                    ->info('Route registration of the auth_kit route loader.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('skip_disabled_features')
                            ->info('Do not register the routes of disabled features (registration_mode, password_reset.mode, magic_login.mode, social_login.mode, qr_login.mode = disabled). false keeps registering them; their controllers redirect to the login page.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('conflict_response')
                    ->info('Since 1.53.0 (REQ-RUNTIME-003 rule 6): the answer of AuthKit\'s pages to a lock conflict (deadlock, lock wait timeout, serialization failure, an AuthKit named lock not obtained). CONFLICT_001 (nothing committed) carries Retry-After; CONFLICT_002 (part of the request already committed) does not. See docs/CONCURRENCY.md.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('status')
                            ->info('409 (Conflict) or 503 (Service Unavailable, the answer up to 1.52). null (default): the status of nowo_transaction_doctrine.conflict_response when NowoTransactionDoctrineBundle is registered, else 409.')
                            ->values([null, ConflictResponsePolicy::STATUS_CONFLICT, ConflictResponsePolicy::STATUS_SERVICE_UNAVAILABLE])
                            ->defaultNull()
                        ->end()
                        ->integerNode('retry_after')
                            ->info('Retry-After seconds of a CONFLICT_001 answer when the failure carries no hint of its own. null (default): the value of nowo_transaction_doctrine.conflict_response when NowoTransactionDoctrineBundle is registered, else 1.')
                            ->min(0)
                            ->defaultNull()
                        ->end()
                        ->booleanNode('commit_detection')
                            ->info('Count the commits of the request (transaction-doctrine\'s commit tracking DBAL middleware), so a conflict after a commit answers CONFLICT_002 instead of inviting a retry that would repeat it. AuthKit reuses the ledger of NowoTransactionDoctrineBundle (commit_detection) when the host has it; otherwise true registers the middleware on the application\'s connections. false: only a host write unit that gave up after a commit (ConcurrencyConflict::$committed) answers CONFLICT_002.')
                            ->defaultTrue()
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
                ->arrayNode('attempt_limiter')
                    ->info('AuthKitAttemptLimiter, the counters of every AuthKit rate limit and lockout (1.42).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')
                            ->info('PSR-6 cache pool service id that stores the counters (e.g. a Redis pool shared by every web node, or a filesystem pool in tests so HTTP-level limit tests survive kernel resets).')
                            ->defaultValue('cache.app')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('personal_data')
                    ->info('GDPR ports (1.45): with UserKit installed and the entities mapped, the invitations eraser / export section and the social_accounts export section register in UserKit.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the 1.45 UserKit adapters (auth_kit_invitations eraser, invitations and social_accounts export sections). Set false when the application already erases / exports these tables. The ports and the 1.36/1.37 erasers are not affected.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('subject_resolver')
                            ->info('Optional service id implementing InvitationPersonalDataSubjectResolverInterface (default: identifiers and e-mails of the user).')
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
                ->append($this->createLoginTargetNode())
                ->append($this->createSessionExpiredNode())
                ->arrayNode('profiles')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->beforeNormalization()
                            ->always()
                            ->then(static fn (mixed $profile): mixed => self::refuseRemovedProfileKeys($profile))
                        ->end()
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
                                ->info('disabled: closed (no registration, no invitation acceptance). first_user_only: register only when no users exist. always: open registration. invite_only (1.32): accounts only from invitations (invitations.mode: enabled required).')
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
                            ->append($this->createPasswordPolicyNode())
                            ->append($this->createSlideToConfirmNode())
                            ->append($this->createDeviceIntelligenceNode())
                            ->append($this->createOtpInputNode())
                            ->arrayNode('registration_fields')
                                ->info('Registration form fields. String names or arrays with name, type, property, hash, required, mapped, slide_to_confirm.')
                                ->defaultValue(['email', 'password'])
                                ->prototype('variable')->end()
                            ->end()
                            ->append($this->createWebUiNode())
                            ->append($this->createEmbedNode())
                            ->append($this->createPasswordResetNode())
                            ->append($this->createMagicLoginNode())
                            ->append($this->createSocialLoginNode())
                            ->append($this->createQrLoginNode())
                            ->append(InvitationsConfiguration::createNode())
                            ->append(MfaConfiguration::createNode())
                            ->append(MultiAccountConfiguration::createNode())
                            ->append(WebauthnConfiguration::createNode())
                            ->append(SamlConfiguration::createNode())
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
                    ->info('Auth route localization (2.0: the root default_locale / enabled_locales / locale_in_path keys are removed).')
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
                        ->scalarNode('unlocalized_route_suffix')
                            ->info('When in_path=both: suffix appended to each route name for its bare (unlocalized) twin, e.g. ".default" names it "<route>.default".')
                            ->defaultValue(self::DEFAULT_UNLOCALIZED_ROUTE_SUFFIX)
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[A-Za-z0-9_.\-]+$/', $value) !== 1)
                                ->thenInvalid('Invalid "nowo_auth_kit.locale.unlocalized_route_suffix" %s: use a non-empty value with letters, digits, "_", "." or "-".')
                            ->end()
                        ->end()
                        ->booleanNode('localized_logout_redirect')
                            ->info('1.32.2, opt-in. When in_path=both and unlocalized=serve: redirect /{_locale}/logout to the bare logout URL (query string kept) so a firewall whose logout.path is the bare twin intercepts both.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * Post-login target guard (1.44): which saved targets a sign-in may redirect to.
     */
    private function createLoginTargetNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('login_target'))->getRootNode();
        $node
            ->info('Post-login target guard (1.44, opt-in): after a sign-in, a saved target that is not a same-origin GET HTML page (API, JSON, asset, Mercure, health, ingest, XHR/fetch URL) is replaced by the fallback. See docs/LOGIN-TARGET.md.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Guard the post-login redirect of the guarded firewalls (opt-in: off by default so a minor release never changes where an existing host redirects after sign-in).')
                    ->defaultFalse()
                ->end()
                ->arrayNode('firewalls')
                    ->info('Firewalls to guard. Empty: the firewall of every profile.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('fallback_route')
                    ->info('Route of the fallback page for every guarded firewall. Null: the login_success_route of the profile on that firewall, else "/".')
                    ->defaultNull()
                ->end()
                ->scalarNode('fallback_provider')
                    ->info('Service id implementing LoginTargetFallbackProviderInterface (per-user fallback, e.g. a start page). Null: none.')
                    ->defaultNull()
                ->end()
                ->arrayNode('denied_path_prefixes')
                    ->info('Path prefixes that are never a page, matched on whole segments ("/health" denies /health and /health/ready, not /healthy). Setting the key replaces the default list.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || preg_match('#^/[^/\\\\]#', $prefix) !== 1)
                            ->thenInvalid('A denied path prefix must start with "/" followed by a segment, %s given.')
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_DENIED_PATH_PREFIXES)
                ->end()
                ->arrayNode('denied_path_patterns')
                    ->info('PCRE patterns matched against the target path (base URL removed). Setting the key replaces the default list.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $pattern): bool => !self::isValidPattern($pattern))
                            ->thenInvalid('Invalid PCRE pattern %s.')
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_DENIED_PATH_PATTERNS)
                ->end()
                ->arrayNode('denied_routes')
                    ->info('Route names that are never a page (bundle JSON routes under page-like names).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('denied_route_patterns')
                    ->info('PCRE patterns matched against the route name of the target.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $pattern): bool => !self::isValidPattern($pattern))
                            ->thenInvalid('Invalid PCRE pattern %s.')
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_DENIED_ROUTE_PATTERNS)
                ->end()
                ->scalarNode('route_option')
                    ->info('Route option that marks a non-page route when set to false (e.g. #[Route(options: [\'html\' => false])]). Null: ignore route options.')
                    ->defaultValue('html')
                ->end()
                ->booleanNode('require_route')
                    ->info('Refuse a target that matches no GET route of the application.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('check_controller_response')
                    ->info('Refuse a route whose controller declares a JsonResponse, StreamedResponse or BinaryFileResponse return type.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('preserve_on_background_requests')
                    ->info('A background call (fetch, XHR, EventSource, JSON) of a signed-out session does not replace the target the firewall saved before.')
                    ->defaultTrue()
                ->end()
            ->end();

        return $node;
    }

    /**
     * Expired-session JSON 401 for background calls (1.44, opt-in).
     */
    private function createSessionExpiredNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('session_expired'))->getRootNode();
        $node
            ->info('Expired-session answer (1.44, opt-in): a fetch/XHR/JSON request that needs authentication gets a 401 application/problem+json with a stable code instead of a 302 to the login page. See docs/LOGIN-TARGET.md.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Answer background calls of the guarded stateful firewalls with the JSON 401.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('firewalls')
                    ->info('Firewalls to answer for. Empty: the firewall of every profile.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('header_name')
                    ->info('Marker header of the 401 answer (front-end code keys on it).')
                    ->defaultValue('X-Auth-Kit')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $name): bool => !is_string($name) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1)
                        ->thenInvalid('Invalid header name %s.')
                    ->end()
                ->end()
                ->scalarNode('header_value')
                    ->info('Value of the marker header.')
                    ->defaultValue('session-expired')
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1)
                        ->thenInvalid('Invalid header value %s.')
                    ->end()
                ->end()
                ->scalarNode('problem_type')
                    ->info('RFC 9457 "type" member.')
                    ->defaultValue('about:blank')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('problem_title')
                    ->info('RFC 9457 "title" member.')
                    ->defaultValue('Unauthorized')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('code')
                    ->info('Stable machine-readable "code" member.')
                    ->defaultValue('session_expired')
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $node;
    }

    private static function isValidPattern(mixed $pattern): bool
    {
        return is_string($pattern) && $pattern !== '' && @preg_match($pattern, '') !== false;
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

    private function createPasswordPolicyNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('password_policy'))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->info('New-password rules of registration and password reset when password_strength is off (since 1.31).')
            ->children()
                ->integerNode('min_length')
                    ->info('Minimum length of a new password (was a fixed 6 before 1.31).')
                    ->defaultValue(self::DEFAULT_PASSWORD_MIN_LENGTH)
                    ->min(8)
                    ->max(4096)
                ->end()
                ->booleanNode('not_compromised')
                    ->info('Also reject passwords found in data breaches (Symfony NotCompromisedPassword, calls the Have I Been Pwned range API).')
                    ->defaultFalse()
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
            ->info('Optional integration with nowo-tech/otp-input-bundle for the password-reset code and the two-step verification (MFA) code. UX only; server checks stay mandatory.')
            ->children()
                ->booleanNode('enabled')
                    ->info('When true, uses OtpType when that bundle is installed (per field: password_reset_code, mfa_code).')
                    ->defaultFalse()
                ->end()
                ->booleanNode('password_reset_code')
                    ->info('Replace the reset OTP TextType with OtpType (length/charset from password_reset).')
                    ->defaultTrue()
                ->end()
                ->booleanNode('mfa_code')
                    ->info('1.51: render the authenticator-app code of the MFA challenge, setup and account forms with OtpType (numeric, mfa.totp.digits boxes). The recovery code stays a text field.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('mfa_code_autosubmit')
                    ->info('1.51: submit the MFA code form once every box is filled (needs mfa_code; JavaScript only).')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }

    private function createWebUiNode(): ArrayNodeDefinition
    {
        $node      = (new TreeBuilder('web_ui'))->getRootNode();
        $templates = (new TreeBuilder('templates'))->getRootNode();
        $templates
            ->info('Per-page template overrides (REQ-UI-003 web_ui.templates.<page>): a host template that extends "@!NowoAuthKit/security/<page>.html.twig" and overrides a data block.')
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

    /** Entity property / embeddable path safe to put in DQL (`passwordResetToken`, `security.resetToken`). */
    private static function isPropertyPath(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $value) === 1;
    }

    /**
     * 2.0: the per-profile `templates.*` and `css.*` keys (deprecated since 1.27) are removed. A profile (or the root
     * shorthand, moved into the default profile) that still sets one fails the build with the replacement, instead of
     * Symfony's generic "unrecognized option".
     *
     * @throws InvalidConfigurationException when the profile sets `templates` or `css`
     */
    private static function refuseRemovedProfileKeys(mixed $profile): mixed
    {
        if (is_array($profile) && array_key_exists('templates', $profile)) {
            throw new InvalidConfigurationException(self::REMOVED_TEMPLATES_KEY);
        }
        if (is_array($profile) && array_key_exists('css', $profile)) {
            throw new InvalidConfigurationException(self::REMOVED_CSS_KEY);
        }

        return $profile;
    }

    /**
     * Resolves web_ui after the config blocks are merged (the former layout `@NowoAuthKitBundle/layout.html.twig` as
     * `web_ui.layout_template` means null) and fills the read-only mirrors `templates.*` (layout, pages, form themes:
     * {@see self::withFormThemes()}, applied by the extension) and `css.*` (the 1.x default class names) that 1.x
     * readers of the processed configuration, of {@see ProfileSettings} and of the `nowo_auth_kit.templates` /
     * `nowo_auth_kit.css` parameters find. Since 2.0 neither key can be configured.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function resolveWebUi(array $profile): array
    {
        /** @var array{layout_template: ?string, templates: array<string, string>, form_themes: ?list<string>, brand: ?string, qr_login: array{stimulus_controller: ?string}} $webUi */
        $webUi = $profile['web_ui'];
        if ($webUi['layout_template'] === self::LEGACY_LAYOUT) {
            $webUi['layout_template'] = null;
        }

        $profile['web_ui']    = $webUi;
        $profile['templates'] = ['layout' => $webUi['layout_template'] ?? self::LEGACY_LAYOUT]
            + $webUi['templates']
            + ['form_theme' => $webUi['form_themes']];
        $profile['css'] = ['button_class' => self::DEFAULT_BUTTON_CLASS, 'secondary_button_class' => self::DEFAULT_SECONDARY_BUTTON_CLASS];

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

    private function createAvailabilityCheckerNode(string $feature): NodeDefinition
    {
        $node = (new TreeBuilder('availability_checker', 'scalar'))->getRootNode();
        $node
            ->info(sprintf('Optional service id implementing FeatureAvailabilityCheckerInterface. False removes the sign-in page link and answers 404 on the %s routes; when set it replaces outbound_mail_ready_checker for this link. null: no check. Not a security control.', $feature))
            ->defaultNull()
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                ->thenInvalid(sprintf('%s.availability_checker must be a service id or null.', $feature))
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
                ->enumNode('storage')
                    ->values([self::PASSWORD_RESET_STORAGE_USER_ENTITY, self::PASSWORD_RESET_STORAGE_TABLE])
                    ->info('Since 1.46.0: where the hashed reset credential lives. user_entity: on the user (token_field / token_expires_field, the earlier behaviour). table: a bundle-owned auth_kit_password_reset row referencing the user by class + id (the user is never written by a reset request; the AuthKit entities must be mapped).')
                    ->defaultValue(self::PASSWORD_RESET_STORAGE_USER_ENTITY)
                ->end()
                ->append($this->createAvailabilityCheckerNode('password_reset'))
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
                ->integerNode('code_rate_limit')
                    ->info('Code submissions allowed per client IP and code_rate_window on the reset code page (since 1.31).')
                    ->defaultValue(10)
                    ->min(1)
                ->end()
                ->integerNode('code_rate_window')
                    ->info('Window in seconds of code_rate_limit.')
                    ->defaultValue(900)
                    ->min(60)
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
                    ->validate()
                        // token_field is concatenated into DQL (PasswordResetTokenManager::findUserByStoredToken()).
                        ->ifTrue(static fn (mixed $value): bool => !self::isPropertyPath($value))
                        ->thenInvalid('Invalid "password_reset.token_field" %s: use a property name (letters, digits, "_"; "." for embeddables).')
                    ->end()
                    ->defaultValue('passwordResetToken')
                ->end()
                ->scalarNode('token_expires_field')
                    ->info('User entity property storing credential expiry.')
                    ->validate()
                        // token_field is concatenated into DQL (PasswordResetTokenManager::findUserByStoredToken()).
                        ->ifTrue(static fn (mixed $value): bool => !self::isPropertyPath($value))
                        ->thenInvalid('Invalid "password_reset.token_expires_field" %s: use a property name (letters, digits, "_"; "." for embeddables).')
                    ->end()
                    ->defaultValue('passwordResetExpiresAt')
                ->end()
                ->scalarNode('password_field')
                    ->info('1.41: user property holding the password hash. A change of it (or of user_identifier_field) in a Doctrine flush clears a pending reset credential (SEC-004 L6). Outside Doctrine, dispatch User\\Event\\CredentialsChangedEvent.')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !self::isPropertyPath($value))
                        ->thenInvalid('Invalid "password_reset.password_field" %s: use a property name (letters, digits, "_"; "." for embeddables).')
                    ->end()
                    ->defaultValue('password')
                ->end()
                ->scalarNode('after_request_route')
                    ->info('Route the reset request page redirects to after a valid submit (e.g. a host "check your email" page that reads PasswordResetCheckEmail from the session). null: back to the login page with a flash.')
                    ->defaultNull()
                ->end()
                ->booleanNode('token_in_session')
                    ->info('Link delivery: move the token from the reset URL into the session and redirect to the token-less URL before rendering the form (keeps the token out of the address bar, history and Referer).')
                    ->defaultFalse()
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
                ->append($this->createAvailabilityCheckerNode('magic_login'))
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
                ->arrayNode('admin_link')
                    ->info('Links an administrator issues for another account (AdminMagicLinkIssuer). Keep the firewall login_link.lifetime >= this lifetime so a used link cannot be replayed.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('lifetime')
                            ->info('Seconds until an administrator-issued link expires.')
                            ->defaultValue(3600)
                            ->min(60)
                        ->end()
                        ->arrayNode('protected_roles')
                            ->info('Accounts holding any of these roles never get an administrator-issued link.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'])
                        ->end()
                    ->end()
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
                    ->info('When true, creates a local user from the social profile email if none exists, only while registration_mode allows registration. Default false since 1.31 (was true).')
                    ->defaultFalse()
                ->end()
                ->enumNode('link_existing_account')
                    ->info('First social sign-in whose verified email matches an existing local account: reset_password (link, then replace the local password with a random one so a password set by whoever registered the address, and its sessions, stop working), deny (refuse; the user signs in locally), keep (pre-1.31: link and keep the password; only when the application verifies e-mail ownership at registration).')
                    ->values(['reset_password', 'deny', 'keep'])
                    ->defaultValue('reset_password')
                ->end()
                ->integerNode('start_rate_limit')
                    ->info('2.3.0 (audit ZBP-L3): sign-in starts (GET /login/social/{provider}) per client IP and start_rate_window, through the attempt limiter; 0 disables. An OpenID Connect start fetches the provider\'s discovery document.')
                    ->defaultValue(20)
                    ->min(0)
                ->end()
                ->integerNode('start_rate_window')
                    ->info('Window in seconds of start_rate_limit.')
                    ->defaultValue(60)
                    ->min(1)
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
                ->arrayNode('authorization_params')
                    ->info('Extra parameters merged into the authorization request, keyed by provider (e.g. google: {prompt: select_account, access_type: offline}). A null value removes a parameter the bundle sends by default for a built-in provider. The credential\'s own authorization_params win over this. The protocol parameters the bundle owns (state, redirect_uri, scope, client_id, response_type, code_challenge*) are refused here.')
                    ->useAttributeAsKey('provider')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->normalizeKeys(false)
                        ->scalarPrototype()->end()
                        ->validate()
                            ->always()
                            ->then(static function (array $params): array {
                                foreach (array_keys($params) as $name) {
                                    if (in_array(strtolower(trim((string) $name)), OAuth2Client::RESERVED_AUTHORIZATION_PARAMS, true)) {
                                        throw new InvalidArgumentException(sprintf('The authorization parameter "%s" is owned by the bundle and cannot be set in "nowo_auth_kit.*.social_login.authorization_params".', $name));
                                    }
                                }

                                return $params;
                            })
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('rp_logout')
                    ->info('2.8.0: OpenID Connect RP-Initiated Logout (per credential: rp_initiated_logout). A sign-out after a sign-in with such a credential also sends the browser to the provider\'s end_session_endpoint.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('post_logout_redirect_uris')
                            ->info('Allow-list of the post_logout_redirect_uri sent to the provider: same-origin paths ("/", "/signed-out") or absolute http(s) URLs (register them at the provider). The sign-out\'s own target when listed, else the first entry; empty: none (the provider shows its own page). Never taken from the request.')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->validate()
                                ->always()
                                ->then(static function (array $uris): array {
                                    foreach ($uris as $uri) {
                                        if (($error = PostLogoutRedirectUri::entryError($uri)) !== null) {
                                            throw new InvalidArgumentException(sprintf('Invalid "nowo_auth_kit.*.social_login.rp_logout.post_logout_redirect_uris" entry %s: use %s.', json_encode($uri), $error));
                                        }
                                    }

                                    return array_values($uris);
                                })
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('provider_marks')
                    ->info('Per-provider brand mark of the login button, keyed by provider. Not listed: the mark the bundle ships for google / github / microsoft, none for any other provider. `false` (or an empty path) removes the mark; a string is the asset path of the host\'s own mark.')
                    ->useAttributeAsKey('provider')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->always()
                            ->then(static function (mixed $mark): array {
                                if ($mark === false || $mark === null) {
                                    return ['path' => null];
                                }
                                if (is_string($mark)) {
                                    return ['path' => $mark];
                                }
                                if (!is_array($mark)) {
                                    throw new InvalidArgumentException('A "nowo_auth_kit.*.social_login.provider_marks" entry is false (no mark), an asset path, or a map with "path", "package" and "on_white".');
                                }

                                return $mark;
                            })
                        ->end()
                        ->children()
                            ->scalarNode('path')
                                ->info('Asset path of the mark (e.g. "images/my-idp.svg"). null: no mark for this provider, which also removes a built-in one.')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('package')
                                ->info('Asset package holding the mark. null: the host\'s default package.')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('on_white')
                                ->info('true: the mark on a white backdrop with clear space; false: none; "auto" (2.4.0): the backdrop in the light theme only, the full-colour mark directly on the button in the dark theme (CSS, data-theme / prefers-color-scheme). Not set: as the bundle ships it for a built-in provider (google "auto", github / microsoft true), off for any other.')
                                ->defaultNull()
                                ->validate()
                                    ->ifTrue(static fn (mixed $value): bool => !is_bool($value) && $value !== ProviderMarkResolver::ON_WHITE_AUTO)
                                    ->thenInvalid('provider_marks.*.on_white must be true, false or "auto", got %s.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
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
                    ->info('session: logged-in phone only. session_step_up: phone session + QrLoginStepUpPolicyInterface (alias it to your policy).')
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
                        ->arrayNode('methods')
                            ->info('HTTP methods of the logout route. [POST] (with the firewall logout.enable_csrf) makes the sign-out a CSRF-protected POST; the embed widget then renders a POST form.')
                            ->beforeNormalization()->castToArray()->end()
                            ->scalarPrototype()
                                ->validate()
                                    ->ifNotInArray(self::LOGOUT_METHODS)
                                    ->thenInvalid('Invalid logout method %s: use GET and/or POST.')
                                ->end()
                            ->end()
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['GET'])
                        ->end()
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
