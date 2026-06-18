<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for AuthKitBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_auth_kit';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('user_class')
                    ->info('FQCN of the application user entity (must implement UserInterface).')
                    ->isRequired()
                    ->cannotBeEmpty()
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
                ->arrayNode('login_fields')
                    ->info('Login form fields. Use identifier (maps to user_identifier_field), password, remember_me.')
                    ->defaultValue(['identifier', 'password'])
                    ->prototype('variable')->end()
                ->end()
                ->arrayNode('registration_fields')
                    ->info('Registration form fields. String names or arrays with name, type, property, hash, required.')
                    ->defaultValue(['email', 'password'])
                    ->prototype('variable')->end()
                ->end()
                ->arrayNode('templates')
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
                    ->end()
                ->end()
                ->arrayNode('routes')
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
                    ->end()
                ->end()
                ->scalarNode('firewall')
                    ->info('Symfony firewall name where form_login should point (documented for security.yaml).')
                    ->defaultValue('main')
                ->end()
                ->scalarNode('login_success_route')
                    ->info('Route name after successful login. Null uses firewall default_target_path.')
                    ->defaultNull()
                ->end()
                ->scalarNode('default_locale')
                    ->defaultValue('en')
                ->end()
                ->arrayNode('enabled_locales')
                    ->scalarPrototype()->end()
                    ->defaultValue(['en', 'es'])
                ->end()
            ->end();

        return $treeBuilder;
    }
}
