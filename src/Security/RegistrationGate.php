<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;

/**
 * Determines whether registration is currently allowed: the open registration page ({@see self::isRegistrationAllowed()})
 * and the acceptance of an invitation ({@see self::isInvitationAcceptanceAllowed()}).
 */
final class RegistrationGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfileRegistry $profileRegistry,
    ) {
    }

    public function isRegistrationAllowed(?string $profileName = null): bool
    {
        $profile = $this->profile($profileName);

        $registrationMode = RegistrationMode::from($profile->registrationMode);

        return match ($registrationMode) {
            RegistrationMode::Disabled, RegistrationMode::InviteOnly => false,
            RegistrationMode::Always                                 => true,
            RegistrationMode::FirstUserOnly                          => $this->countUsers($profile->userClass) === 0,
        };
    }

    /**
     * Whether an invitation may be accepted: `invitations.mode: enabled` and a registration mode other than
     * `disabled` (closed). Independent of the user count (`first_user_only` keeps accepting invitations).
     */
    public function isInvitationAcceptanceAllowed(?string $profileName = null): bool
    {
        $profile = $this->profile($profileName);

        return $profile->invitationsEnabled() && RegistrationMode::from($profile->registrationMode) !== RegistrationMode::Disabled;
    }

    private function profile(?string $profileName): ProfileSettings
    {
        return $profileName !== null
            ? $this->profileRegistry->getByName($profileName)
            : $this->profileRegistry->getDefault();
    }

    /**
     * @param class-string $userClass
     */
    private function countUsers(string $userClass): int
    {
        return $this->entityManager
            ->getRepository($userClass)
            ->count([]);
    }
}
