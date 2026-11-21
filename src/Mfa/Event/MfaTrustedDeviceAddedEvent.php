<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\Event;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The user trusted this browser to skip the second step until it expires (`device` is its public id).
 */
final class MfaTrustedDeviceAddedEvent extends MfaEvent
{
    public function __construct(
        string $profile,
        UserInterface $user,
        public readonly string $device,
    ) {
        parent::__construct($profile, $user);
    }

    /**
     * The `$device` value (1.55.0): read it here; same value as the property.
     */
    public function device(): string
    {
        return $this->device;
    }
}
