<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationOutcome;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host authorisation hook (1.33, SEC-004 CP L1; autoconfigured, tag `nowo_auth_kit.invitation_action_authorizer`).
 * AuthKit authorises by Symfony roles only, so for an invitation without roles that check always passes. Every
 * authorizer is asked before each action and **all** must grant (deny only: it cannot lift AuthKit's role rule);
 * a denial answers {@see InvitationOutcome::NOT_ALLOWED}.
 *
 * Calls: `invite` (the request, before anything is stored), `reinvite` (the open invitation a re-invite would merge
 * into; the request is passed as `$request`), `resend` and `revoke` (the invitation), `return_link` (the request on
 * invite, the invitation on resend, when the link is returned to the caller). Hosts whose payload carries grants
 * (e.g. `payload[merge_key]` = organization, an org role) must check the actor on them here.
 */
interface InvitationActionAuthorizerInterface
{
    public const AUTHORIZER_TAG = 'nowo_auth_kit.invitation_action_authorizer';

    public const INVITE = 'invite';

    public const REINVITE = 'reinvite';

    public const RESEND = 'resend';

    public const REVOKE = 'revoke';

    public const RETURN_LINK = 'return_link';

    public function isGranted(?UserInterface $actor, string $action, Invitation|InvitationRequest $subject, ?InvitationRequest $request = null): bool;
}
