<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

/**
 * 1.56.0: the autoconfiguration tag and the action names of the invitation action hook, declared once and inherited by
 * both {@see InvitationActionPolicyInterface} and the deprecated {@see InvitationActionAuthorizerInterface} (a class
 * implementing both keeps compiling). Read them as `InvitationActionPolicyInterface::INVITE`, … (2.0 keeps them there).
 *
 * @internal
 */
interface InvitationActionNames
{
    public const AUTHORIZER_TAG = 'nowo_auth_kit.invitation_action_authorizer';

    public const INVITE = 'invite';

    public const REINVITE = 'reinvite';

    public const RESEND = 'resend';

    public const REVOKE = 'revoke';

    public const RETURN_LINK = 'return_link';
}
