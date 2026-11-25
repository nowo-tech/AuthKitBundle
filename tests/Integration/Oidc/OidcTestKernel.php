<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\HostResolverInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCredentialIssuerGuard;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\RecordingLogger;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeResolver;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with social login on and a fake OpenID Connect provider behind the HTTP client (2.3.0): the
 * discovery / JWKS / token / userinfo answers come from {@see FakeIdp}, host names resolve through {@see FakeResolver},
 * discovery documents and key sets live in an array pool.
 */
final class OidcTestKernel extends AuthKitTestKernel
{
    public const POOL = 'test.oidc_cache';

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/oidc-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/oidc-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', [
            'http_client' => ['mock_response_factory' => FakeIdp::class],
            'cache'       => ['pools' => [self::POOL => ['adapter' => 'cache.adapter.array']]],
        ]);
        $container->loadFromExtension('nowo_auth_kit', [
            'social_login' => ['mode' => 'enabled'],
            'oidc'         => ['cache_pool' => self::POOL],
        ]);
        $container->register(FakeIdp::class)->setPublic(true);
        $container->register(FakeResolver::class)->setPublic(true);
        $container->setAlias(HostResolverInterface::class, FakeResolver::class)->setPublic(true);
        $container->register('logger', RecordingLogger::class)->setPublic(true);
        $container->setAlias('test.issuer_guard', SocialCredentialIssuerGuard::class)->setPublic(true);
    }
}
