<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

/**
 * No delivery configured (symfony/mailer missing): nothing is sent; the inviter sees "not sent" or, with
 * `invitations.show_link_when_undelivered`, the link once.
 */
final class NullInvitationMailer implements InvitationMailerInterface
{
    public function send(InvitationMail $mail): bool
    {
        return false;
    }
}
