<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with the 1.37 host extensions: both availability checkers and a social callback handler.
 */
final class HostExtensionsTestKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/host-extensions-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/host-extensions-' . $this->environment . '/log';
    }

    /**
     * `/test/pending`: the step a host stores before sending the user to the provider.
     */
    public function pending(Request $request): Response
    {
        $request->getSession()->set(PendingStepCallbackHandler::SESSION_KEY, true);

        return new Response('pending');
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', ['http_client' => ['mock_response_factory' => SocialProviderResponses::class]]);
        $container->loadFromExtension('nowo_auth_kit', [
            'magic_login'    => ['availability_checker' => 'test.magic_checker'],
            'password_reset' => ['availability_checker' => 'test.reset_checker'],
            'social_login'   => ['mode' => 'enabled'],
        ]);
        $container->register('test.magic_checker', ToggleAvailabilityChecker::class)->setPublic(true);
        $container->register('test.reset_checker', ToggleAvailabilityChecker::class)->setPublic(true);
        $container->register(SocialProviderResponses::class)->setPublic(true);
        $container->register(PendingStepCallbackHandler::class)->setAutoconfigured(true)->setPublic(true);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('test_pending', '/test/pending')->controller('kernel::pending');
    }
}
