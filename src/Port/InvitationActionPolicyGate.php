<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Port;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Invitation\InvitationRequest;
use Nowo\AuthKitBundle\Invitation\InvitationSnapshot;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionAuthorizerInterface;
use Nowo\AuthKitBundle\Invitation\Security\InvitationActionPolicyInterface;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Asks a host {@see InvitationActionPolicyInterface} (1.46.0) where the bundle asks the deprecated
 * {@see InvitationActionAuthorizerInterface}: the actor becomes an {@see AuthUserRef},
 * the invitation entity an {@see InvitationSnapshot} as of `$now` (the request stays as is). Only `true` grants;
 * exceptions propagate. Built per call by {@see Ports}.
 *
 * @internal
 */
final readonly class InvitationActionPolicyGate implements InvitationActionAuthorizerInterface
{
    public function __construct(
        private InvitationActionPolicyInterface $policy,
        private DateTimeImmutable $now,
    ) {
    }

    public function isGranted(?UserInterface $actor, string $action, Invitation|InvitationRequest $subject, ?InvitationRequest $request = null): bool
    {
        return $this->policy->allows(
            AuthUserRefs::fromNullableUser($actor),
            $action,
            $subject instanceof Invitation ? InvitationSnapshot::of($subject, $this->now) : $subject,
            $request,
        ) === true;
    }
}
