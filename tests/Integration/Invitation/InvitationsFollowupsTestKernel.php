<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Nowo\AuthKitBundle\Port\Internal\UserLookupInterface;
use Nowo\AuthKitBundle\SocialLogin\Oidc\HostResolverInterface;
use Nowo\AuthKitBundle\Tests\Integration\HostExtensions\SocialProviderResponses;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeResolver;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function sys_get_temp_dir;

/**
 * The host-like invitations kernel with the 1.42 follow-ups switched on: social acceptance on the accept page (fake
 * identity provider), the invitation kept after an e-mail mismatch, the provider name in `nickname`, bounded batches
 * (3 per batch, one batch per window, ten single invitations per inviter), the attempt counters in their own
 * filesystem pool and a host template reading the invitation snapshot.
 */
final class InvitationsFollowupsTestKernel extends InvitationsHostApiTestKernel
{
    public const POOL = 'test.auth_kit_attempts';

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-followups-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-followups-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', [
            'http_client' => ['mock_response_factory' => SocialProviderResponses::class],
            'cache'       => ['pools' => [self::POOL => ['adapter' => 'cache.adapter.filesystem'], 'test.oidc_cache' => ['adapter' => 'cache.adapter.array']]],
        ]);
        $container->loadFromExtension('twig', ['paths' => ['%kernel.project_dir%/tests/Integration/Invitation/templates/followups' => 'InvitationFollowups']]);
        $container->loadFromExtension('nowo_auth_kit', [
            'attempt_limiter' => ['cache_pool' => self::POOL],
            'oidc'            => ['cache_pool' => 'test.oidc_cache'],
            'social_login'    => ['mode' => 'enabled'],
            'web_ui'          => ['templates' => ['invitation_accept' => '@InvitationFollowups/invitation_accept.html.twig']],
            'invitations'     => [
                'invite_rate_limit' => 10,
                'accept_rate_limit' => 20,
                'batch'             => ['max_size' => 3, 'rate_limit' => 1],
                'social'            => ['accept_page' => true, 'keep_pending_on_mismatch' => true, 'name_field' => 'nickname'],
            ],
        ]);
        $container->register(SocialProviderResponses::class)->setPublic(true);
        // 2.3.0: OIDC host names resolve through the fake resolver (no DNS in the tests).
        $container->register(FakeResolver::class)->setPublic(true);
        $container->setAlias(HostResolverInterface::class, FakeResolver::class)->setPublic(true);
        $container->register(ToggleUserLookup::class)->setDecoratedService(UserLookupInterface::class)->setArguments([new Reference('.inner')])->setPublic(true);
    }
}
