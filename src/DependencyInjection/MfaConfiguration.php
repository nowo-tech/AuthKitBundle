<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * `profiles.<name>.mfa` (1.34): native two-factor authentication (TOTP + recovery codes, trusted devices). Off by
 * default (`enabled: false`: no service, route, entity mapping with `register_mapping: auto`, cookie or listener).
 */
final class MfaConfiguration
{
    /** First factor needs the second step once the user has MFA. */
    public const RULE_REQUIRED = 'required';

    /** First factor never asks for the second step (forced enrolment still applies). */
    public const RULE_EXEMPT = 'exempt';

    /** Remember-me only: no challenge (its cookie is only issued after a completed second step). */
    public const RULE_TRUSTED = 'trusted';

    /** Remember-me only: every remember-me sign-in is challenged (unless the device is trusted). */
    public const RULE_CHALLENGE = 'challenge';

    /** First-factor key => default rule (D-M9: every factor but QR login requires the second step). */
    public const FIRST_FACTORS = [
        'form_login'   => self::RULE_REQUIRED,
        'login_link'   => self::RULE_REQUIRED,
        'qr'           => self::RULE_EXEMPT,
        'social'       => self::RULE_REQUIRED,
        'registration' => self::RULE_REQUIRED,
        'invitation'   => self::RULE_REQUIRED,
        'default'      => self::RULE_REQUIRED,
    ];

    /** Route key => [default path, default name, admin route]. */
    public const ROUTES = [
        'challenge'      => ['/login/mfa', 'nowo_auth_kit_mfa_challenge', false],
        'setup'          => ['/login/mfa/setup', 'nowo_auth_kit_mfa_setup', false],
        'account'        => ['/account/security/mfa', 'nowo_auth_kit_mfa_account', false],
        'enable'         => ['/account/security/mfa/enable', 'nowo_auth_kit_mfa_enable', false],
        'disable'        => ['/account/security/mfa/disable', 'nowo_auth_kit_mfa_disable', false],
        'recovery_codes' => ['/account/security/mfa/recovery-codes', 'nowo_auth_kit_mfa_recovery_codes', false],
        'forget_device'  => ['/account/security/mfa/devices/{device}/forget', 'nowo_auth_kit_mfa_forget_device', false],
        'admin_reset'    => ['/admin/mfa/{user}', 'nowo_auth_kit_mfa_admin_reset', true],
    ];

    public static function createNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('mfa'))->getRootNode();
        $node
            ->info('Native two-factor authentication (1.34): TOTP + recovery codes, trusted devices, role policy. Off by default.')
            ->addDefaultsIfNotSet()
            ->validate()
                ->always(static function (array $mfa): array {
                    // The kernel secret is referenced only by enabled profiles (a disabled profile adds no parameter dependency).
                    if ($mfa['enabled'] === true && $mfa['hash_secret'] === null) {
                        $mfa['hash_secret'] = '%kernel.secret%';
                    }

                    return $mfa;
                })
            ->end()
            ->children()
                ->booleanNode('enabled')
                    ->info('false: no MFA service, route, entity (register_mapping: auto), cookie or listener for this profile.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('issuer')
                    ->info('Name shown in authenticator apps (otpauth issuer). Required when enabled.')
                    ->defaultNull()
                ->end()
                ->booleanNode('require_encryption')
                    ->info('true: the container refuses to compile unless nowo-tech/doctrine-encrypt-bundle is registered (the TOTP secret is #[Encrypted]). Set false only for tests / demos.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('totp')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('digits')->defaultValue(6)->min(6)->max(8)->end()
                        ->integerNode('period')->defaultValue(30)->min(15)->max(120)->end()
                        ->integerNode('window')
                            ->info('Accepted clock skew in time steps on each side (RFC 6238 §5.2).')
                            ->defaultValue(1)->min(0)->max(2)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('recovery_codes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('count')->defaultValue(10)->min(6)->max(20)->end()
                        ->integerNode('low_warning')
                            ->info('The account page warns when this many unused codes or fewer are left.')
                            ->defaultValue(3)->min(0)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('required_roles')
                    ->info('Users holding one of these roles (role hierarchy aware) must have MFA (grace_period, then forced enrolment).')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $role): bool => !is_string($role) || preg_match('/^ROLE_[A-Z0-9_]+$/', $role) !== 1)
                            ->thenInvalid('Invalid mfa.required_roles entry %s: use ROLE_* names.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('policy')
                    ->info('Service id of a Mfa\MfaPolicyInterface (host rules, e.g. organisations that require MFA), asked after required_roles.')
                    ->defaultNull()
                ->end()
                ->integerNode('grace_period')
                    ->info('Seconds a required user may still sign in without MFA (from the first such sign-in); 0 = enrol at the next sign-in.')
                    ->defaultValue(604800)->min(0)
                ->end()
                ->integerNode('pending_lifetime')
                    ->info('Seconds a sign-in may wait for its second step.')
                    ->defaultValue(300)->min(60)->max(3600)
                ->end()
                ->integerNode('recent_sign_in')
                    ->info('Seconds after a full sign-in during which the account page may set up the second factor (DD-I1); later the user signs in again first.')
                    ->defaultValue(900)->min(60)->max(86400)
                ->end()
                ->integerNode('max_attempts')
                    ->info('Wrong codes per pending sign-in; then the sign-in ends (the session is invalidated).')
                    ->defaultValue(5)->min(1)->max(20)
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(self::limitNode('account', 10, 900))
                        ->append(self::limitNode('ip', 30, 900))
                    ->end()
                ->end()
                ->append(self::firstFactorsNode())
                ->enumNode('remember_me')
                    ->info('trusted (D-M6): a remember-me sign-in is not challenged (its cookie is only issued after the second step). challenge: challenge it unless the device is trusted.')
                    ->values([self::RULE_TRUSTED, self::RULE_CHALLENGE])
                    ->defaultValue(self::RULE_TRUSTED)
                ->end()
                ->arrayNode('trusted_devices')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('lifetime')
                            ->info('Seconds a trusted device skips the second step (0 = no "trust this device" option).')
                            ->defaultValue(2592000)->min(0)
                        ->end()
                        ->integerNode('max')
                            ->info('Trusted devices per user when UserKit gives no maxTrustedDevices (the least recently used one is forgotten).')
                            ->defaultValue(5)->min(1)->max(50)
                        ->end()
                        ->scalarNode('password_field')
                            ->info('Mapped field of the user entity holding the password hash: a change forgets the trusted devices (DD-I6).')
                            ->defaultValue('password')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('allowed_routes')
                    ->info('Extra route names reachable while a sign-in waits for its second step (e.g. a locale switch).')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('hash_secret')
                    ->info('HMAC key of recovery codes and trusted-device tokens (null: %kernel.secret% when enabled).')
                    ->defaultNull()
                ->end()
                ->arrayNode('admin')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('routes')
                            ->info('true: register the admin reset page (D-M10: off by default; MfaManager::reset() is the PHP API).')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
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

    private static function firstFactorsNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('first_factors'))->getRootNode();
        $children = $node
            ->info('Second step per first factor: required | exempt (D-M9: qr exempt, the rest required). Exempt never skips forced enrolment.')
            ->addDefaultsIfNotSet()
            ->children();
        foreach (self::FIRST_FACTORS as $factor => $rule) {
            $children->enumNode($factor)->values([self::RULE_REQUIRED, self::RULE_EXEMPT])->defaultValue($rule)->end();
        }
        $children->end();

        return $node;
    }

    private static function routesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('routes'))->getRootNode();
        $children = $node
            ->info('Paths and names of the MFA routes (registered only with enabled: true; admin_reset only with admin.routes: true).')
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
                            ->thenInvalid('Invalid MFA route path %s: it must start with a single "/".')
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
