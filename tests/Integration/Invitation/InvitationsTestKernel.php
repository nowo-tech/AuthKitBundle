<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Nowo\AuthKitBundle\Invitation\InvitationMailerInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with invitations enabled (registration_mode: invite_only, one grantable role, the e-mail
 * locked), a capturing mailer and an audit/grant recorder.
 */
class InvitationsTestKernel extends AuthKitTestKernel
{
    /** invitations.show_link_when_undelivered */
    protected const SHOW_LINK = false;

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'registration_mode'   => 'invite_only',
            'absolute_url'        => ['base_uri' => 'https://app.example.test'],
            'registration_fields' => [
                'email',
                'password',
                ['name' => 'nickname', 'required' => false],
                ['name' => 'newsletter', 'type' => 'checkbox', 'required' => false],
                ['name' => 'terms', 'type' => 'checkbox', 'mapped' => false, 'required' => false],
            ],
            'invitations' => [
                'mode'                       => 'enabled',
                'roles'                      => ['ROLE_EDITOR', 'ROLE_SUPER_ADMIN'],
                'show_link_when_undelivered' => static::SHOW_LINK,
                'accept_rate_limit'          => 50,
            ],
        ]);
        $container->loadFromExtension('security', [
            'role_hierarchy' => ['ROLE_ADMIN' => ['ROLE_EDITOR']],
            'firewalls'      => ['main' => ['user_checker' => BlockingUserChecker::class]],
        ]);
        $container->register(BlockingUserChecker::class);

        $container->register(CapturingInvitationMailer::class)->setPublic(true);
        $container->setAlias(InvitationMailerInterface::class, CapturingInvitationMailer::class)->setPublic(true);
        $container->register(CapturingInvitationListener::class)->setPublic(true)->setAutoconfigured(true);
    }
}
