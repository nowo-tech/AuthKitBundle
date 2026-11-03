<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The MFA kernel with UserKit sessions limited to one per user (`reject_new`).
 */
final class MfaRejectNewTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-reject-new';

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
            'sessions'   => ['enabled' => true, 'limit' => ['max_sessions' => 1, 'policy' => 'reject_new']],
        ]);
    }
}
