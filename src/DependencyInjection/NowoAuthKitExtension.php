<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Loads bundle configuration and registers services.
 */
final class NowoAuthKitExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $container->setParameter('nowo_auth_kit.user_class', $config['user_class']);
        $container->setParameter('nowo_auth_kit.user_identifier_field', $config['user_identifier_field']);
        $container->setParameter('nowo_auth_kit.registration_role', $config['registration_role']);
        $container->setParameter('nowo_auth_kit.registration_mode', $config['registration_mode']);
        $container->setParameter(
            'nowo_auth_kit.login_fields',
            FieldConfigNormalizer::normalizeLoginFields($config['login_fields'], $config['user_identifier_field']),
        );
        $container->setParameter(
            'nowo_auth_kit.registration_fields',
            FieldConfigNormalizer::normalizeRegistrationFields($config['registration_fields']),
        );
        $container->setParameter('nowo_auth_kit.templates', $config['templates']);
        $container->setParameter('nowo_auth_kit.routes', $config['routes']);
        $container->setParameter('nowo_auth_kit.firewall', $config['firewall']);
        $container->setParameter('nowo_auth_kit.login_success_route', $config['login_success_route']);
        $container->setParameter('nowo_auth_kit.default_locale', $config['default_locale']);
        $container->setParameter('nowo_auth_kit.enabled_locales', $config['enabled_locales']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
