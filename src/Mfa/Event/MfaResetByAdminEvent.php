<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An administrator cleared the user's second factor (recovery codes and trusted devices too); the user re-enrols.
 * Dispatched after the write.
 */
final class MfaResetByAdminEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly UserInterface $actor,
    ) {
        parent::__construct($profile, $user);
    }
}
