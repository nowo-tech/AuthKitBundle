<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Invitation\InvitationGrantHandlerInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\Mfa\MfaRequirementPolicyInterface;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentationProviderInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentPolicyInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackStepInterface;
use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;
use Nowo\AuthKitBundle\ValueObject\SocialCallbackResult;

/**
 * {@see PortRule} through the framework-free 1.46 twins only.
 */
class TwinRulePorts implements MfaRequirementPolicyInterface, InvitationsAccessPolicyInterface, EnrolmentPolicyInterface, AccountPresentationProviderInterface, InvitationActionPolicyInterface, InvitationGrantHandlerInterface, SocialCallbackStepInterface
{
    public function __construct(public readonly PortLog $log = new PortLog())
    {
    }

    public function requirementFor(AuthUserRef $user, string $profile): MfaRequirement
    {
        return PortRule::allows($user->identifier, $user->roles, $profile) ? MfaRequirement::Required : MfaRequirement::Optional;
    }

    public function allowsManage(AuthUserRef $user, string $profile): bool
    {
        return PortRule::allows($user->identifier, $user->roles, $profile);
    }

    public function allowsEnrolment(EnrolmentCandidate $candidate): bool
    {
        return $candidate->user instanceof AuthUserRef && !$candidate->impersonated && PortRule::allows($candidate->user->identifier, $candidate->roleNames, 'default');
    }

    public function presentationFor(AuthUserRef $user): AccountPresentation
    {
        return new AccountPresentation('legacy:' . $user->identifier, $user->identifier);
    }

    public function allows(?AuthUserRef $actor, string $action, InvitationSnapshot|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        return $actor instanceof AuthUserRef && PortRule::allows($actor->identifier, $actor->roles, 'default', $subject->email, $action);
    }

    public function grantTo(InvitationSnapshot $invitation, AuthUserRef $user): void
    {
        $this->log->entries[] = 'grant ' . $invitation->email . ' to ' . $user->identifier;
        PortRule::allows($user->identifier, $user->roles, $invitation->profile);
    }

    public function supports(AuthRequestContext $request, string $provider): bool
    {
        return $provider === 'google' && $request->sessionHas('pending');
    }

    public function handle(AuthRequestContext $request, SocialCallbackContext $context): SocialCallbackResult
    {
        return SocialCallbackResult::redirect('/done');
    }

    public function handleFailure(AuthRequestContext $request, string $provider, SocialCallbackFailure $reason): ?SocialCallbackResult
    {
        return null;
    }
}
