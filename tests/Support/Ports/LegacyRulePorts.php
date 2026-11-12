<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Ports;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Enum\SocialCallbackFailure;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface;
use Nowo\AuthKitBundle\Mfa\MfaPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\MultiAccount\AccountPresentation;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentGateInterface;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackContext;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * {@see PortRule} through the deprecated (1.45) interfaces.
 */
class LegacyRulePorts implements MfaPolicyInterface, InvitationsAccessCheckerInterface, EnrolmentGateInterface, AccountPresenterInterface, InvitationActionAuthorizerInterface, InvitationGrantInterface, SocialCallbackHandlerInterface
{
    public function __construct(public readonly PortLog $log = new PortLog())
    {
    }

    public function requirement(UserInterface $user, string $profile): MfaRequirement
    {
        return PortRule::allows($user->getUserIdentifier(), $user->getRoles(), $profile) ? MfaRequirement::Required : MfaRequirement::Optional;
    }

    public function canManage(UserInterface $user, string $profile): bool
    {
        return PortRule::allows($user->getUserIdentifier(), $user->getRoles(), $profile);
    }

    public function isComplete(TokenInterface $token): bool
    {
        $user = $token->getUser();

        return $user instanceof UserInterface && !$token instanceof SwitchUserToken && PortRule::allows($user->getUserIdentifier(), $token->getRoleNames(), 'default');
    }

    public function present(UserInterface $user): AccountPresentation
    {
        return new AccountPresentation('legacy:' . $user->getUserIdentifier(), $user->getUserIdentifier());
    }

    public function isGranted(?UserInterface $actor, string $action, Invitation|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        $email = $subject instanceof Invitation ? $subject->getEmail() : $subject->email;

        return $actor instanceof UserInterface && PortRule::allows($actor->getUserIdentifier(), $actor->getRoles(), 'default', $email, $action);
    }

    public function grant(InvitationSnapshot $invitation, UserInterface $user): void
    {
        $this->log->entries[] = 'grant ' . $invitation->email . ' to ' . $user->getUserIdentifier();
        PortRule::allows($user->getUserIdentifier(), $user->getRoles(), $invitation->profile);
    }

    public function supports(Request $request, string $provider): bool
    {
        return $provider === 'google' && $request->getSession()->has('pending');
    }

    public function handle(Request $request, SocialCallbackContext $context): Response
    {
        return new Response('legacy');
    }

    public function handleFailure(Request $request, string $provider, SocialCallbackFailure $reason): ?Response
    {
        return null;
    }
}
