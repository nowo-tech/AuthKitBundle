<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

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
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Nowo\AuthKitBundle\ValueObject\EnrolmentCandidate;
use RuntimeException;

/**
 * Implements both interfaces: the deprecated methods follow {@see PortRule}, the 1.46 ones must never be asked (they
 * throw), so the matrix proves the deprecated interface wins until 2.0. (The social callback interfaces share method names with
 * incompatible signatures: no class can implement both.)
 */
final class BothRulePorts extends LegacyRulePorts implements MfaRequirementPolicyInterface, InvitationsAccessPolicyInterface, EnrolmentPolicyInterface, AccountPresentationProviderInterface, InvitationActionPolicyInterface, InvitationGrantHandlerInterface
{
    public function requirementFor(AuthUserRef $user, string $profile): MfaRequirement
    {
        throw new RuntimeException('twin asked');
    }

    public function allowsManage(AuthUserRef $user, string $profile): bool
    {
        throw new RuntimeException('twin asked');
    }

    public function allowsEnrolment(EnrolmentCandidate $candidate): bool
    {
        throw new RuntimeException('twin asked');
    }

    public function presentationFor(AuthUserRef $user): AccountPresentation
    {
        throw new RuntimeException('twin asked');
    }

    public function allows(?AuthUserRef $actor, string $action, InvitationSnapshot|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        throw new RuntimeException('twin asked');
    }

    public function grantTo(InvitationSnapshot $invitation, AuthUserRef $user): void
    {
        throw new RuntimeException('twin asked');
    }
}
