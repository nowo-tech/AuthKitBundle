<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Invitation\Security;

use Nowo\AuthKitBundle\Port\Ports;
use Psr\Container\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Access to the invitation administration of a profile: a signed-in user the profile's checker accepts
 * (`invitations.security`), or anyone when `allow_unauthenticated` is true (DEV/DEMO only: the extension refuses it
 * unless kernel.debug is true). The checkers are a locator keyed by profile name. Also tells which grantable roles the
 * user holds (an inviter never grants a role they do not have).
 */
final readonly class InvitationsAccessGuard
{
    /**
     * @param array<string, bool> $allowUnauthenticated profile => allow_unauthenticated
     */
    public function __construct(
        private ContainerInterface $checkers,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
        private array $allowUnauthenticated = [],
        private ?AccessDecisionManagerInterface $decisionManager = null,
    ) {
    }

    /**
     * Whether `$actor` holds every role of `$roles` (role hierarchy, through the access decision manager on a token of
     * that user — also for an actor other than the signed-in user). No role: true. No actor or no decision manager
     * (SecurityBundle missing): false (SEC-004 CD M1 — fail closed).
     *
     * @param list<string> $roles
     */
    public function holdsAll(?UserInterface $actor, array $roles): bool
    {
        if ($roles === []) {
            return true;
        }
        if (!$actor instanceof UserInterface || !$this->decisionManager instanceof AccessDecisionManagerInterface) {
            return false;
        }
        $token = new UsernamePasswordToken($actor, 'nowo_auth_kit_invitations', $actor->getRoles());
        foreach ($roles as $role) {
            if (!$this->decisionManager->decide($token, [$role])) {
                return false;
            }
        }

        return true;
    }

    public function user(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    public function canManage(string $profile): bool
    {
        if (($this->allowUnauthenticated[$profile] ?? false) === true) {
            return true;
        }
        $user = $this->user();
        if (!$user instanceof UserInterface || !$this->checkers->has($profile)) {
            return false;
        }
        $checker = Ports::invitationsAccessChecker($this->checkers->get($profile));

        return $checker instanceof InvitationsAccessCheckerInterface && $checker->canManage($user, $profile);
    }

    /**
     * The roles of `$grantable` the signed-in user holds (none without a user or an authorization checker).
     *
     * @param list<string> $grantable
     *
     * @return list<string>
     */
    public function grantableRoles(array $grantable): array
    {
        if (!$this->user() instanceof UserInterface || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return [];
        }
        $roles = [];
        foreach ($grantable as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }
}
