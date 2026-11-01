<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

/**
 * Delivers an invitation. Default: {@see MailerInvitationMailer} (Symfony Mailer, templates and subject from
 * `invitations.mail` and the translations) when a `mailer.mailer` service exists, else {@see NullInvitationMailer}.
 * Override by aliasing this interface to your own service.
 */
interface InvitationMailerInterface
{
    /**
     * @return bool whether the invitation left (false: no delivery configured or the transport failed)
     */
    public function send(InvitationMail $mail): bool;
}
