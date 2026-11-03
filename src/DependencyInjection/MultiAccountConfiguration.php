<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * `profiles.<name>.multi_account` (1.34): several accounts signed in at once in one browser, each in its own session,
 * with an account switcher. Off by default (`enabled: false`: no service, route, entity mapping with
 * `register_mapping: auto`, cookie or listener). Needs UserKit ≥ 1.11 sessions (D1).
 */
final class MultiAccountConfiguration
{
    /** The normal sign-out ends every account of the browser (D2, Google behaviour). */
    public const LOGOUT_ALL = 'all';

    /** The normal sign-out ends the active account only. */
    public const LOGOUT_CURRENT = 'current';

    /** Route key => [default path, default name]. */
    public const ROUTES = [
        'chooser'      => ['/accounts', 'nowo_auth_kit_accounts'],
        'add'          => ['/accounts/add', 'nowo_auth_kit_accounts_add'],
        'switch'       => ['/accounts/{account}/switch', 'nowo_auth_kit_accounts_switch'],
        'sign_out'     => ['/accounts/{account}/sign-out', 'nowo_auth_kit_accounts_sign_out'],
        'sign_out_all' => ['/accounts/sign-out-all', 'nowo_auth_kit_accounts_sign_out_all'],
    ];

    public static function createNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('multi_account'))->getRootNode();
        $node
            ->info('Several accounts signed in at once with an account switcher (1.34). Off by default; needs UserKit >= 1.11 sessions.')
            ->addDefaultsIfNotSet()
            ->validate()
                ->always(static function (array $config): array {
                    // The kernel secret is referenced only by enabled profiles.
                    if ($config['enabled'] === true && $config['hash_secret'] === null) {
                        $config['hash_secret'] = '%kernel.secret%';
                    }

                    return $config;
                })
            ->end()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->integerNode('max_accounts')
                    ->info('Accounts one browser may hold.')
                    ->defaultValue(5)->min(2)->max(10)
                ->end()
                ->integerNode('max_lifetime')
                    ->info('Seconds an account stays in the switcher after its last full sign-in in this browser (D10: 30 days).')
                    ->defaultValue(2592000)->min(3600)
                ->end()
                ->enumNode('logout_scope')
                    ->info('What the firewall sign-out ends: all (D2, every account of the browser) or current (the active account; the chooser follows).')
                    ->values([self::LOGOUT_ALL, self::LOGOUT_CURRENT])
                    ->defaultValue(self::LOGOUT_ALL)
                ->end()
                ->booleanNode('revive_with_remember_me')
                    ->info('Switching to an account whose parked session expired restores its own remember-me cookie (D9).')
                    ->defaultTrue()
                ->end()
                ->scalarNode('after_switch')
                    ->info('Route after a switch; null: the profile login_success_route.')
                    ->defaultNull()
                ->end()
                ->integerNode('rotation_grace')
                    ->info('Seconds a rotated vault token is still accepted (concurrent tabs); later it counts as reuse (theft).')
                    ->defaultValue(30)->min(0)->max(300)
                ->end()
                ->scalarNode('hash_secret')
                    ->info('HMAC key of the vault tokens (null: %kernel.secret% when enabled).')
                    ->defaultNull()
                ->end()
                ->arrayNode('cookie')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')
                            ->defaultValue('nowo_auth_kit_accounts')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[A-Za-z0-9_\-]{1,64}\z/', $value) !== 1)
                                ->thenInvalid('Invalid multi_account.cookie.name %s.')
                            ->end()
                        ->end()
                        ->integerNode('lifetime')
                            ->info('null (D3): a browser-session cookie; seconds: persistent.')
                            ->defaultNull()->min(60)
                        ->end()
                        ->enumNode('samesite')->values(['lax', 'strict'])->defaultValue('lax')->end()
                    ->end()
                ->end()
                ->arrayNode('chooser')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('on_login_page')->defaultTrue()->end()
                        ->enumNode('identifier_display')
                            ->info('What the login-page chooser shows next to the name (D4: masked e-mail).')
                            ->values(['full', 'masked', 'none'])
                            ->defaultValue('masked')
                        ->end()
                        ->booleanNode('cross_tab_notice')
                            ->info('D7 (opt-in): other tabs show "you switched account" after a switch.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limit')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(self::limitNode('switch', 30, 600))
                        ->append(self::limitNode('add', 10, 3600))
                        ->append(self::limitNode('invalid', 20, 600))
                    ->end()
                ->end()
                ->scalarNode('enrolment_gate')
                    ->info('Service id of a MultiAccount\EnrolmentGateInterface; null: a sign-in is complete once AuthKit MFA (if any) passed.')
                    ->defaultNull()
                ->end()
                ->scalarNode('presenter')
                    ->info('Service id of a MultiAccount\AccountPresenterInterface (name, secondary line, avatar); null: the identifier.')
                    ->defaultNull()
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
            ->info('Paths and names of the account-switcher routes (registered only with enabled: true).')
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
                            ->thenInvalid('Invalid multi-account route path %s: it must start with a single "/".')
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
