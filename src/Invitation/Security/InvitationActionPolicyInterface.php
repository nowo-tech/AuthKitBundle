<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host authorisation hook (1.46.0; autoconfigured, tag `nowo_auth_kit.invitation_action_authorizer`), framework-free
 * (REQ-ARCH-001 rules 1 and 3): an {@see AuthUserRef} actor and an {@see InvitationSnapshot}. AuthKit authorises by
 * Symfony roles only, so for an invitation without roles that check always passes. Every policy is asked before each
 * action and **all** must allow (deny only: it cannot lift AuthKit's role rule); a denial answers
 * {@see InvitationOutcome::NOT_ALLOWED}.
 *
 * Calls: `invite` (the request, before anything is stored), `reinvite` (the open invitation a re-invite would merge
 * into; the request is passed as `$request`), `resend` and `revoke` (the invitation), `return_link` (the request on
 * invite, the invitation on resend, when the link is returned to the caller). Hosts whose payload carries grants
 * (e.g. `payload[merge_key]` = organization, an org role) must check the actor on them here.
 *
 * 2.0: the only interface of this hook (the 1.33 `InvitationActionAuthorizerInterface` is removed); the tag and the
 * action names moved here from it.
 */
interface InvitationActionPolicyInterface
{
    public const AUTHORIZER_TAG = 'nowo_auth_kit.invitation_action_authorizer';

    public const INVITE = 'invite';

    public const REINVITE = 'reinvite';

    public const RESEND = 'resend';

    public const REVOKE = 'revoke';

    public const RETURN_LINK = 'return_link';

    public function allows(?AuthUserRef $actor, string $action, InvitationSnapshot|InvitationRequest $subject, ?InvitationRequest $request = null): bool;
}
