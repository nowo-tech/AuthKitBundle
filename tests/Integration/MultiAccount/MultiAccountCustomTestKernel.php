<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\MultiAccount;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The multi-account kernel with host choices: `logout_scope: current`, no remember-me revival, Strict persistent
 * cookie, full identifiers, cross-tab notice, tight rate limits, a host gate and presenter.
 */
final class MultiAccountCustomTestKernel extends MultiAccountTestKernel
{
    public const NAME = 'multi-account-custom';

    protected const MULTI_ACCOUNT = [
        'logout_scope'            => 'current',
        'revive_with_remember_me' => false,
        'cookie'                  => ['samesite' => 'strict', 'lifetime' => 3600],
        'chooser'                 => ['identifier_display' => 'full', 'cross_tab_notice' => true],
        'rate_limit'              => ['add' => ['limit' => 2, 'window' => 600], 'switch' => ['limit' => 2, 'window' => 600]],
        'enrolment_gate'          => HostAccountPolicy::class,
        'presenter'               => HostAccountPolicy::class,
    ];

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->register(HostAccountPolicy::class);
    }
}
