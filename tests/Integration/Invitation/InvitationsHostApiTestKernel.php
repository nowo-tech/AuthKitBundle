<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * The invitations kernel configured like a host with its own invitation UI (1.33): `registration_role: ROLE_ADMIN`
 * (first user) with `invitations.registration_role: ROLE_MEMBER`, one open invitation per (e-mail, organization) with
 * deep payload merge, no admin routes, and a recorder of the host hooks.
 */
final class InvitationsHostApiTestKernel extends InvitationsTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-host-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-host-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'registration_role' => 'ROLE_ADMIN',
            'invitations'       => [
                'registration_role' => 'ROLE_MEMBER',
                'merge_key'         => 'organization',
                'payload_merge'     => 'deep',
                'admin_routes'      => false,
            ],
        ]);
        $container->register(HostHookListener::class)->setPublic(true)->setAutoconfigured(true);
    }
}
