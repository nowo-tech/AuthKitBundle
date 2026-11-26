<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function is_string;
use function parse_url;
use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * `profiles.<name>.webauthn` (2.4.0): passkeys (WebAuthn Level 3) as a passwordless first factor and as a second factor
 * next to TOTP. Off by default (`mode: disabled`: no service, route, listener or script); the table is mapped with the
 * other AuthKit entities. User verification is always required (a passkey alone is then multi-factor).
 */
final class WebauthnConfiguration
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_ENABLED = 'enabled';

    public const ATTESTATION_NONE = 'none';

    public const ATTESTATION_DIRECT = 'direct';

    /** Route key => [default path, default name]. */
    public const ROUTES = [
        'login_options'    => ['/login/webauthn/options', 'nowo_auth_kit_webauthn_login_options'],
        'login_assert'     => ['/login/webauthn/assert', 'nowo_auth_kit_webauthn_login_assert'],
        'mfa_options'      => ['/login/mfa/webauthn/options', 'nowo_auth_kit_webauthn_mfa_options'],
        'mfa_assert'       => ['/login/mfa/webauthn/assert', 'nowo_auth_kit_webauthn_mfa_assert'],
        'account'          => ['/account/webauthn', 'nowo_auth_kit_webauthn_account'],
        'register_options' => ['/account/webauthn/register/options', 'nowo_auth_kit_webauthn_register_options'],
        'register'         => ['/account/webauthn/register', 'nowo_auth_kit_webauthn_register'],
        'rename'           => ['/account/webauthn/{id}/rename', 'nowo_auth_kit_webauthn_rename'],
        'delete'           => ['/account/webauthn/{id}/delete', 'nowo_auth_kit_webauthn_delete'],
    ];

    /** Routes that answer a second step of a pending sign-in (reachable while MFA contains the browser). */
    public const MFA_ROUTES = ['mfa_options', 'mfa_assert'];

    public static function createNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('webauthn'))->getRootNode();
        $node
            ->info('Passkeys / WebAuthn (2.4.0): passwordless sign-in with conditional UI and a second factor next to TOTP. Off by default.')
            ->addDefaultsIfNotSet()
            ->validate()
                ->always(static function (array $webauthn): array {
                    // The kernel secret is referenced only by enabled profiles (a disabled profile adds no parameter dependency).
                    if ($webauthn['mode'] === self::MODE_ENABLED && $webauthn['hash_secret'] === null) {
                        $webauthn['hash_secret'] = '%kernel.secret%';
                    }

                    return $webauthn;
                })
            ->end()
            ->children()
                ->enumNode('mode')
                    ->info('disabled: no WebAuthn service, route, listener or script for this profile.')
                    ->values([self::MODE_DISABLED, self::MODE_ENABLED])
                    ->defaultValue(self::MODE_DISABLED)
                ->end()
                ->scalarNode('rp_id')
                    ->info('Relying party id (a registrable domain, e.g. "example.com"); null = the request host. Changing it later orphans every registered passkey. With a parent domain, list the origins that serve the login page in allowed_origins.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $id): bool => $id !== null && (!is_string($id) || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $id) !== 1))
                        ->thenInvalid('Invalid webauthn.rp_id %s: use a lowercase host name without scheme or port.')
                    ->end()
                ->end()
                ->arrayNode('allowed_origins')
                    ->info('Exact origins (https://host[:port], lower-cased at build time) a ceremony may come from. Empty = exactly https://<rp_id> (audit ZBX-M1: never the request Host; with rp_id null the RP id is the request host, so the origin follows it, on the default port only); list every origin that serves the login page when it is not that one (e.g. https://login.example.com with rp_id example.com). http:// only for localhost / 127.0.0.1.')
                    ->scalarPrototype()
                        // 2.4.1 (audit ZBX-v-I3): a browser origin is lower case; an upper-case entry would never match.
                        ->beforeNormalization()
                            ->ifString()
                            ->then(static fn (string $origin): string => strtolower($origin))
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (mixed $origin): bool => !is_string($origin) || preg_match('#^(https://[^/?\#@\s]+|http://(localhost|127\.0\.0\.1)(:\d+)?)$#', $origin) !== 1 || parse_url($origin) === false)
                            ->thenInvalid('Invalid webauthn.allowed_origins entry %s: use https://host[:port] without path (http:// only for localhost).')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('timeout_ms')
                    ->info('Ceremony timeout given to the browser; the server-side challenge expires 30 seconds after it.')
                    ->defaultValue(60000)->min(10000)->max(600000)
                ->end()
                ->enumNode('attestation')
                    ->info('none (default, privacy friendly) or direct: the authenticator statement (packed, tpm, android-key, apple, fido-u2f) must verify; there are no trust anchors, so it is not a model allow-list.')
                    ->values([self::ATTESTATION_NONE, self::ATTESTATION_DIRECT])
                    ->defaultValue(self::ATTESTATION_NONE)
                ->end()
                ->integerNode('max_credentials')
                    ->info('Passkeys per account in this profile (Beacon: 10).')
                    ->defaultValue(10)->min(1)->max(100)
                ->end()
                ->integerNode('recent_sign_in')
                    ->info('Seconds after a full sign-in during which the account may add, rename or delete a passkey; later it signs in again first.')
                    ->defaultValue(900)->min(60)->max(86400)
                ->end()
                ->booleanNode('passwordless')
                    ->info('true: "Sign in with a passkey" and the conditional UI (autocomplete="username webauthn") on the login page.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('mfa')
                    ->info('true: a passkey is a second factor next to TOTP when the profile has mfa.enabled (it satisfies a required second factor).')
                    ->defaultTrue()
                ->end()
                ->scalarNode('hash_secret')
                    ->info('HMAC key of the opaque user handle given to new passkeys (null: %kernel.secret% when enabled). Never personal data. Rotating it keeps every registered passkey working (each row keeps its handle).')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $secret): bool => $secret !== null && (!is_string($secret) || $secret === ''))
                        ->thenInvalid('webauthn.hash_secret must be a non-empty string (or null for %%kernel.secret%%), got %s.')
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->info('Attempt windows of the ceremony routes: options and assert per client IP, register per account.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(self::limitNode('options', 30, 300))
                        ->append(self::limitNode('assert', 20, 300))
                        ->append(self::limitNode('register', 10, 900))
                    ->end()
                ->end()
                ->append(self::routesNode())
            ->end();

        return $node;
    }

    private static function limitNode(string $name, int $limit, int $window): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('limit')->defaultValue($limit)->min(1)->end()
                ->integerNode('window')->defaultValue($window)->min(60)->end()
            ->end();

        return $node;
    }

    private static function routesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('routes'))->getRootNode();
        $children = $node
            ->info('Paths and names of the WebAuthn routes (registered only with mode: enabled; login_* only with passwordless, mfa_* only with mfa and mfa.enabled).')
            ->addDefaultsIfNotSet()
            ->children();
        foreach (self::ROUTES as $key => [$path, $name]) {
            $children->arrayNode($key)
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('path')
                        ->defaultValue($path)
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || !str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with($value, '/\\'))
                            ->thenInvalid('Invalid WebAuthn route path %s: it must start with a single "/".')
                        ->end()
                    ->end()
                    ->scalarNode('name')->defaultValue($name)->cannotBeEmpty()->end()
                ->end()
            ->end();
        }
        $children->end();

        return $node;
    }
}
