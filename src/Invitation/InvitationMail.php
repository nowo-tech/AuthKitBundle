<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

/**
 * What an {@see InvitationMailerInterface} sends: the invitation (no token hash), its accept link (absolute, on
 * `absolute_url.base_uri`; the only place the token leaves the bundle besides the opt-in "shown once" page) and
 * whether it is a resend.
 */
final readonly class InvitationMail
{
    public function __construct(
        public InvitationSnapshot $invitation,
        public string $acceptUrl,
        public bool $resend = false,
    ) {
    }
}
