<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Ddd;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpInterface;
use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\CapturingPasswordResetNotifier;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\UxIconStubExtension;
use Nowo\AuthKitBundle\Tests\Stub\HostDomainUser;
use Nowo\AuthKitBundle\Tests\Support\Ports\InMemoryTwinUsers;
use Nowo\AuthKitBundle\Tests\Support\Ports\StepUpHost;
use Nowo\AuthKitBundle\User\UserCreatorInterface;
use Nowo\AuthKitBundle\User\UserDirectoryInterface;
use Nowo\AuthKitBundle\User\UserLookupInterface;
use Nowo\AuthKitBundle\User\UserStoreInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * REQ-ARCH-001 DDD host (1.46.0): the security user is a plain host class ({@see HostDomainUser}, no Doctrine
 * mapping), served by an adapter on the framework-free user ports only ({@see InMemoryTwinUsers}: no deprecated
 * interface), the reset credential lives in the bundle's own table (`password_reset.storage: table`), the bundle maps
 * its entities from XML (`doctrine.mapping_type: xml`) and writes them through its private entity manager
 * (`doctrine.private_entity_manager: true`), and the QR step-up is a 1.46 policy. The host keeps a Doctrine entity of its
 * own ({@see \Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser}) on the default manager to prove AuthKit
 * never flushes it.
 */
final class DddHostTestKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/ddd-host/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/ddd-host/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret'                => 'auth-kit-test',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'validation'            => ['enabled' => true],
            'translator'            => ['enabled' => true, 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
            'php_errors'            => ['log' => false],
        ]);
        $container->loadFromExtension('twig', ['strict_variables' => true]);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/auth_kit.sqlite'],
            'orm'  => [
                'mappings' => [
                    'KernelTest' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => '%kernel.project_dir%/tests/Integration/Kernel/Entity',
                        'prefix'    => 'Nowo\\AuthKitBundle\\Tests\\Integration\\Kernel\\Entity',
                    ],
                ],
            ],
        ]);
        $container->loadFromExtension('security', [
            'password_hashers' => [HostDomainUser::class => 'plaintext'],
            'providers'        => ['host' => ['id' => InMemoryTwinUsers::class]],
            'firewalls'        => [
                'main' => [
                    'lazy'       => true,
                    'provider'   => 'host',
                    'form_login' => [
                        'login_path'          => 'nowo_auth_kit_login',
                        'check_path'          => 'nowo_auth_kit_login',
                        'default_target_path' => 'kernel_home',
                        'enable_csrf'         => true,
                        'username_parameter'  => 'login_form[_username]',
                        'password_parameter'  => 'login_form[_password]',
                        'csrf_parameter'      => 'login_form[_csrf_token]',
                        'csrf_token_id'       => 'authenticate',
                    ],
                    'logout' => ['path' => 'nowo_auth_kit_logout'],
                ],
            ],
        ]);
        $container->loadFromExtension('nowo_ui_kit', ['css_framework' => 'none']);
        $container->loadFromExtension('nowo_auth_kit', [
            'user_class'          => HostDomainUser::class,
            'registration_mode'   => 'always',
            'login_success_route' => 'kernel_home',
            'password_reset'      => ['mode' => 'enabled', 'delivery' => 'link', 'storage' => 'table'],
            'qr_login'            => ['mode' => 'enabled', 'approve_requires' => 'session_step_up'],
            'doctrine'            => ['private_entity_manager' => true, 'mapping_type' => 'xml'],
            'web_ui'              => ['brand' => 'Acme'],
        ]);

        $container->register('logger', NullLogger::class);
        $container->register(UxIconStubExtension::class)->addTag('twig.extension');
        $container->register(CapturingPasswordResetNotifier::class)->setPublic(true);
        $container->setAlias(PasswordResetNotifierInterface::class, CapturingPasswordResetNotifier::class)->setPublic(true);

        // The host's adapters, on the 1.46 interfaces only.
        $container->register(InMemoryTwinUsers::class)->setPublic(true);
        $container->setAlias(UserCreatorInterface::class, InMemoryTwinUsers::class);
        $container->setAlias(UserStoreInterface::class, InMemoryTwinUsers::class);
        $container->setAlias(UserDirectoryInterface::class, InMemoryTwinUsers::class);
        $container->register(StepUpHost::class);
        $container->setAlias(QrLoginStepUpPolicyInterface::class, StepUpHost::class);

        $container->register(PendingHostChangeListener::class)
            ->setAutowired(true)
            ->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 4096]);

        $container->setAlias('test.ddd.user_lookup', UserLookupInterface::class)->setPublic(true);
        $container->setAlias('test.ddd.qr_step_up', QrLoginStepUpInterface::class)->setPublic(true);
    }
}
