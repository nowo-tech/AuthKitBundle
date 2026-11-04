<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.35 (audit EC): the host presenter (avatars) with `chooser.other_account_avatars: true`, `identifier_display: none`
 * and `logout_scope: current` (a parked account stays after the active one signs out: the shared-browser case).
 */
final class MultiAccountAvatarOptInTestKernel extends MultiAccountTestKernel
{
    public const NAME = 'multi-account-avatar-opt-in';

    protected const MULTI_ACCOUNT = [
        'logout_scope'            => 'current',
        'revive_with_remember_me' => false,
        'chooser'                 => ['identifier_display' => 'none', 'other_account_avatars' => true],
        'presenter'               => HostAccountPolicy::class,
    ];

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->register(HostAccountPolicy::class);
    }
}
