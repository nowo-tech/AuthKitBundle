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
 * Actions: the constants of {@see InvitationActionAuthorizerInterface}.
 */
interface InvitationActionPolicyInterface
{
    public function allows(?AuthUserRef $actor, string $action, InvitationSnapshot|InvitationRequest $subject, ?InvitationRequest $request = null): bool;
}
