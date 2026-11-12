<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Legacy;

use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationGrantInterface;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationsAccessCheckerInterface;
use Nowo\AuthKitBundle\Mfa\MfaPolicyInterface;
use Nowo\AuthKitBundle\Mfa\MfaRequirement;
use Nowo\AuthKitBundle\MultiAccount\AccountPresenterInterface;
use Nowo\AuthKitBundle\MultiAccount\DefaultEnrolmentGate;
use Nowo\AuthKitBundle\MultiAccount\EnrolmentGateInterface;
use Nowo\AuthKitBundle\MultiAccount\IdentifierAccountPresenter;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\SocialLogin\SocialCallbackHandlerInterface;
use Nowo\AuthKitBundle\Tests\Unit\Port\PortEquivalenceTest;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_intersect;
use function array_values;
use function is_array;
use function is_string;

/**
 * LITERAL copies of the v1.45.2 (2efbec1) port decision code, for the 1.46.0 equivalence tests
 * ({@see PortEquivalenceTest}). Each body is the 1.45.2 method body verbatim; only
 * `$this->x` fields became parameters. Never edit a body: a change here hides a behaviour change of the bundle.
 *
 * Sources: `MfaManager::requirement()` (after `profileFor()`), `InvitationsAccessGuard::canManage()` (after the
 * unauthenticated / user / has checks), `AccountVault::gate()` / `presenter()`, `InvitationManager::authorized()`,
 * the grant loop of `InvitationAcceptor::accept…()`, `SocialLoginCheckController::handlerFor()`.
 */
final class Legacy145PortDecisions
{
    /** MfaManager::requirement() from `$required = …` on (the profile is the MFA profile of the user). */
    public static function mfaRequirement(ProfileSettings $profile, UserInterface $user, ?RoleHierarchyInterface $roleHierarchy, ?ContainerInterface $policies): MfaRequirement
    {
        $required = array_values(array_filter(is_array($profile->mfa['required_roles'] ?? null) ? $profile->mfa['required_roles'] : [], is_string(...)));
        if ($required !== []) {
            $roles = $roleHierarchy?->getReachableRoleNames($user->getRoles()) ?? $user->getRoles();
            if (array_intersect($required, $roles) !== []) {
                return MfaRequirement::Required;
            }
        }
        if ($policies?->has($profile->name) === true) {
            $policy = $policies->get($profile->name);
            if ($policy instanceof MfaPolicyInterface) {
                return $policy->requirement($user, $profile->name);
            }
        }

        return MfaRequirement::Optional;
    }

    /** InvitationsAccessGuard::canManage() from `$checker = …` on. */
    public static function canManage(ContainerInterface $checkers, UserInterface $user, string $profile): bool
    {
        $checker = $checkers->get($profile);

        return $checker instanceof InvitationsAccessCheckerInterface && $checker->canManage($user, $profile);
    }

    /** AccountVault::gate(). */
    public static function gate(ContainerInterface $gates, ProfileSettings $profile): EnrolmentGateInterface
    {
        $gate = $gates->has($profile->name) ? $gates->get($profile->name) : null;

        return $gate instanceof EnrolmentGateInterface ? $gate : new DefaultEnrolmentGate();
    }

    /** AccountVault::presenter(). */
    public static function presenter(ContainerInterface $presenters, ProfileSettings $profile): AccountPresenterInterface
    {
        $presenter = $presenters->has($profile->name) ? $presenters->get($profile->name) : null;

        return $presenter instanceof AccountPresenterInterface ? $presenter : new IdentifierAccountPresenter();
    }

    /**
     * InvitationManager::authorized().
     *
     * @param iterable<InvitationActionAuthorizerInterface> $authorizers
     */
    public static function authorized(iterable $authorizers, ?UserInterface $actor, string $action, Invitation|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        foreach ($authorizers as $authorizer) {
            if (!$authorizer->isGranted($actor, $action, $subject, $request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The grant loop of InvitationAcceptor (inside the acceptance transaction).
     *
     * @param iterable<InvitationGrantInterface> $grants
     */
    public static function grants(iterable $grants, InvitationSnapshot $snapshot, UserInterface $user): void
    {
        foreach ($grants as $grant) {
            $grant->grant($snapshot, $user);
        }
    }

    /**
     * SocialLoginCheckController::handlerFor().
     *
     * @param iterable<SocialCallbackHandlerInterface> $callbackHandlers
     */
    public static function handlerFor(iterable $callbackHandlers, Request $request, string $provider): ?SocialCallbackHandlerInterface
    {
        foreach ($callbackHandlers as $handler) {
            if ($handler->supports($request, $provider)) {
                return $handler;
            }
        }

        return null;
    }
}
