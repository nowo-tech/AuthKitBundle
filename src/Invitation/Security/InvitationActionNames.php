<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

/**
 * 1.56.0: the autoconfiguration tag and the action names of the invitation action hook, inherited by
 * {@see InvitationActionPolicyInterface} (in 1.x also by the deprecated `InvitationActionAuthorizerInterface`, removed in
 * 2.0). Read them as `InvitationActionPolicyInterface::INVITE`, ….
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
