<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The MFA kernel with UserKit 1.11 sessions (remember-me epoch, one trusted device per user from UserKit's limit, a
 * header device id resolver) and `mfa.remember_me: challenge`.
 */
final class MfaSessionsTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-sessions';

    protected const MFA = ['remember_me' => 'challenge', 'policy' => RequireMembersPolicy::class];

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new NowoUserKitBundle();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_user_kit', [
            'user_class' => KernelUser::class,
            'sessions'   => [
                'enabled'           => true,
                'remember_me_epoch' => ['enabled' => true],
                'limit'             => ['max_trusted_devices' => 1],
                'client'            => ['device_id_resolver' => HeaderDeviceIdResolver::class],
            ],
        ]);
        $container->register(HeaderDeviceIdResolver::class);
        $container->register(RequireMembersPolicy::class);
    }
}
