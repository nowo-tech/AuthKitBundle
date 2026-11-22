<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation;

/**
 * 1.56.0: the autoconfiguration tag of the invitation grant hook, inherited by {@see InvitationGrantHandlerInterface}
 * (in 1.x also by the deprecated `InvitationGrantInterface`, removed in 2.0). Read it as
 * `InvitationGrantHandlerInterface::TAG`.
 *
 * @internal
 */
interface InvitationGrantTag
{
    public const TAG = 'nowo_auth_kit.invitation_grant';
}
