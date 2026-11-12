<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Nowo\AuthKitBundle\Port\AuthUserRefs;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An administrator cleared the user's second factor (recovery codes and trusted devices too); the user re-enrols.
 * Dispatched after the write.
 */
final class MfaResetByAdminEvent extends MfaEvent
{
    /**
     * @param UserInterface $actor @deprecated since 1.46.0, use actorRef(); removed in 2.0
     */
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly UserInterface $actor,
    ) {
        parent::__construct($profile, $user);
    }

    /**
     * The administrator, framework-free (1.46.0).
     */
    public function actorRef(): AuthUserRef
    {
        return AuthUserRefs::fromUser($this->actor);
    }
}
