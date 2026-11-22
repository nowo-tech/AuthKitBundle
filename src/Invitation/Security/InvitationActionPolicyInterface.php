<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Host authorisation hook (1.46.0; autoconfigured, tag `nowo_auth_kit.invitation_action_authorizer`): the
 * framework-free twin of the deprecated {@see InvitationActionAuthorizerInterface} (REQ-ARCH-001 rules 1 and 3): an
 * {@see AuthUserRef} actor and an {@see InvitationSnapshot} instead of the `Invitation` entity. Same calls and the same
 * deny-only rule (every authorizer must grant). A service implementing both interfaces is asked through the deprecated
 * one until 2.0.
 *
 * 1.56.0: the tag and the action names are constants of this interface (`InvitationActionPolicyInterface::AUTHORIZER_TAG`,
 * `INVITE`, `REINVITE`, `RESEND`, `REVOKE`, `RETURN_LINK`; inherited from {@see InvitationActionNames}, same values as
 * on the deprecated interface). Use them here: 2.0 removes the deprecated interface and keeps them on this one.
 */
interface InvitationActionPolicyInterface extends InvitationActionNames
{
    public function allows(?AuthUserRef $actor, string $action, InvitationSnapshot|InvitationRequest $subject, ?InvitationRequest $request = null): bool;
}
