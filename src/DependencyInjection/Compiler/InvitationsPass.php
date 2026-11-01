<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\AuthKitBundle\Invitation\InvitationMailerInterface;
use Nowo\AuthKitBundle\Invitation\MailerInvitationMailer;
use Nowo\AuthKitBundle\Invitation\NullInvitationMailer;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mailer\MailerInterface;

use function interface_exists;
use function is_array;
use function sprintf;

/**
 * Invitations (1.32):
 *
 * - the default {@see InvitationMailerInterface} is {@see MailerInvitationMailer} only when Symfony Mailer is wired
 *   (else {@see NullInvitationMailer}; a host alias of the interface is left alone);
 * - a profile with `invitations.mode: enabled` needs SecurityBundle (token storage and authorization checker) for its
 *   administration pages unless `invitations.security.allow_unauthenticated` is true (REQ-UI-002: fail at compile).
 */
final class InvitationsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->wireMailer($container);

        if (!$container->hasParameter('nowo_auth_kit.profiles')) {
            return;
        }
        $profiles = $container->getParameter('nowo_auth_kit.profiles');
        if (!is_array($profiles)) {
            return;
        }

        foreach ($profiles as $name => $profile) {
            if (!is_array($profile) || ($profile['invitations']['mode'] ?? 'disabled') !== 'enabled') {
                continue;
            }
            if (($profile['invitations']['security']['allow_unauthenticated'] ?? false) === true) {
                continue;
            }
            if (!$container->has('security.token_storage') || !$container->has('security.authorization_checker')) {
                throw new LogicException(sprintf('NowoAuthKitBundle: profiles.%s.invitations needs SecurityBundle for its administration pages (or invitations.security.allow_unauthenticated: true, DEV/DEMO only).', $name));
            }
        }
    }

    private function wireMailer(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(InvitationMailerInterface::class)
            || (string) $container->getAlias(InvitationMailerInterface::class) !== MailerInvitationMailer::class
        ) {
            return;
        }
        if (interface_exists(MailerInterface::class) && ($container->has(MailerInterface::class) || $container->has('mailer.mailer'))) {
            return;
        }
        $container->removeDefinition(MailerInvitationMailer::class);
        $container->setAlias(InvitationMailerInterface::class, NullInvitationMailer::class);
    }
}
