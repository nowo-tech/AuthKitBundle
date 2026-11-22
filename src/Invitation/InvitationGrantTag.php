<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

/**
 * 1.56.0: the autoconfiguration tag of the invitation grant hook, declared once and inherited by both
 * {@see InvitationGrantHandlerInterface} and the deprecated {@see InvitationGrantInterface} (a class implementing both
 * keeps compiling). Read it as `InvitationGrantHandlerInterface::TAG` (2.0 keeps it there).
 *
 * @internal
 */
interface InvitationGrantTag
{
    public const TAG = 'nowo_auth_kit.invitation_grant';
}
