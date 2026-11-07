<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

use function getenv;
use function is_string;
use function md5;

/**
 * Runs on the database of `AUTH_KIT_TEST_DB_URL` (the CI job `real-databases`), else on SQLite.
 */
final class SwallowedDatabaseErrorKernel extends ProgrammaticLoginTestKernel
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/' . md5((string) getenv('AUTH_KIT_TEST_DB_URL'));
    }

    protected function authenticators(): array
    {
        return ['form_login' => ['login_path' => 'nowo_auth_kit_login', 'check_path' => 'nowo_auth_kit_login', 'enable_csrf' => true]];
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $url = getenv('AUTH_KIT_TEST_DB_URL');
        if (is_string($url) && $url !== '') {
            $container->loadFromExtension('doctrine', ['dbal' => ['url' => $url]]);
        }
        $container->register(SwallowingDatabaseListener::class)
            ->setArgument('$connection', new Reference('doctrine.dbal.default_connection'))
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => LoginSuccessEvent::class, 'method' => 'onLoginSuccess', 'dispatcher' => 'security.event_dispatcher.main']);
    }
}
