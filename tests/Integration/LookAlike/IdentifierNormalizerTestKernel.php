<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\MagicLogin\MagicLoginUserResolver;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestHandler;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Security\UserIdentifierMatcher;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\SocialLogin\SocialAccountLinker;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function getenv;
use function is_string;
use function md5;
use function str_ends_with;
use function sys_get_temp_dir;

/**
 * The standard test kernel on the database of `AUTH_KIT_TEST_DB_URL` (CI job `real-databases`: MySQL 8.4, MariaDB
 * 11.4, PostgreSQL; else SQLite). Environment `*_lower` sets `identifier_normalizer: lowercase_ascii_email`; any other
 * environment keeps the default normaliser. The services of every lookup-by-identifier flow get public test ids.
 */
final class IdentifierNormalizerTestKernel extends AuthKitTestKernel
{
    /** @var array<string, string> public test id => service */
    public const SERVICES = [
        'test.identifier.user_lookup'   => UserLookupInterface::class,
        'test.identifier.matcher'       => UserIdentifierMatcher::class,
        'test.identifier.magic_login'   => MagicLoginUserResolver::class,
        'test.identifier.reset_request' => PasswordResetRequestHandler::class,
        'test.identifier.reset_tokens'  => PasswordResetTokenManagerInterface::class,
        'test.identifier.social'        => SocialAccountLinker::class,
        'test.identifier.admin'         => AdminUserCreator::class,
        'test.identifier.registrar'     => UserRegistrar::class,
        'test.identifier.profiles'      => ProfileRegistry::class,
    ];

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/identifier-normalizer/' . $this->environment . '/' . md5((string) getenv('AUTH_KIT_TEST_DB_URL')) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/identifier-normalizer/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $url = getenv('AUTH_KIT_TEST_DB_URL');
        if (is_string($url) && $url !== '') {
            $container->loadFromExtension('doctrine', ['dbal' => ['url' => $url]]);
        }
        if (str_ends_with($this->environment, '_lower')) {
            $container->loadFromExtension('nowo_auth_kit', ['identifier_normalizer' => 'lowercase_ascii_email']);
        }
        foreach (self::SERVICES as $id => $service) {
            $container->setAlias($id, $service)->setPublic(true);
        }
    }
}
