<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

use function is_string;
use function str_contains;
use function str_starts_with;

/**
 * `profiles.<name>.saml` (2.10.0): SAML 2.0 Service Provider sign-in, SP-initiated only. Off by default (`mode:
 * disabled`: no service, route or listener); the provider table is mapped with the other AuthKit entities. Linking and
 * provisioning follow the profile's `social_login` settings (`create_user_if_missing`, `require_verified_email`,
 * `link_existing_account`), as for an OpenID Connect credential.
 */
final class SamlConfiguration
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_ENABLED = 'enabled';

    /** Most clock skew accepted on the assertion's time conditions (seconds). */
    public const MAX_CLOCK_SKEW = 180;

    /** Route key => [default path, default name]; every path holds `{provider}`. */
    public const ROUTES = [
        'start'    => ['/login/saml/{provider}', 'nowo_auth_kit_saml_login_start'],
        'acs'      => ['/login/saml/{provider}/acs', 'nowo_auth_kit_saml_acs'],
        'metadata' => ['/saml/{provider}/metadata', 'nowo_auth_kit_saml_metadata'],
    ];

    public static function createNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('saml'))->getRootNode();
        $node
            ->info('SAML 2.0 Service Provider sign-in (2.10.0, requires onelogin/php-saml): SP metadata, signed AuthnRequest (HTTP-Redirect) and Assertion Consumer Service (HTTP-POST) per provider row (auth_kit_saml_provider). SP-initiated only. Off by default.')
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('mode')
                    ->info('disabled: no SAML service or route for this profile.')
                    ->values([self::MODE_DISABLED, self::MODE_ENABLED])
                    ->defaultValue(self::MODE_DISABLED)
                ->end()
                ->integerNode('clock_skew')
                    ->info('Seconds of clock difference accepted on NotBefore / NotOnOrAfter / SessionNotOnOrAfter (0 to 180).')
                    ->defaultValue(60)->min(0)->max(self::MAX_CLOCK_SKEW)
                ->end()
                ->integerNode('request_lifetime')
                    ->info('Seconds a sign-in started here may take at the identity provider before its response is refused.')
                    ->defaultValue(600)->min(60)->max(3600)
                ->end()
                ->integerNode('start_rate_limit')
                    ->info('Sign-in starts per client IP and start_rate_window, through the attempt limiter; 0 disables.')
                    ->defaultValue(20)->min(0)
                ->end()
                ->integerNode('start_rate_window')
                    ->info('Window in seconds of start_rate_limit.')
                    ->defaultValue(60)->min(1)
                ->end()
                ->append(self::routesNode())
            ->end();

        return $node;
    }

    private static function routesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('routes'))->getRootNode();
        $children = $node
            ->info('Paths and names of the SAML routes (registered only with mode: enabled). Every path holds {provider}.')
            ->addDefaultsIfNotSet()
            ->children();
        foreach (self::ROUTES as $key => [$path, $name]) {
            $children->arrayNode($key)
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('path')
                        ->defaultValue($path)
                        ->validate()
                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || !str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with($value, '/\\') || !str_contains($value, '{provider}'))
                            ->thenInvalid('Invalid SAML route path %s: it must start with a single "/" and hold {provider}.')
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
