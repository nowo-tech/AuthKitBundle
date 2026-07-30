<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Config\FieldConfigNormalizer;
use Nowo\AuthKitBundle\Config\RememberMeConfigResolver;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function sprintf;

/**
 * Loads bundle configuration and registers services.
 */
final class NowoAuthKitExtension extends Extension
{
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
        $this->normalizeProfiles($profiles);

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
        $container->setParameter('nowo_auth_kit.registration_fields', $defaultProfile['registration_fields']);
        $container->setParameter('nowo_auth_kit.templates', $defaultProfile['templates']);
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
        $container->setParameter('nowo_auth_kit.social_login', $defaultProfile['social_login']);
        $container->setParameter('nowo_auth_kit.social_login.mode', $defaultProfile['social_login']['mode']);
        $container->setParameter('nowo_auth_kit.social_login.create_user_if_missing', $defaultProfile['social_login']['create_user_if_missing']);
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

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     */
    private function normalizeProfiles(array &$profiles): void
    {
        foreach ($profiles as $name => &$profile) {
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
            /** @var array<string, array{path: string, name: string}> $routes */
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
}
