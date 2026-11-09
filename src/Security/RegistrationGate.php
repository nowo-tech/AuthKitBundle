<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Security;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\AuthKitBundle\Enum\RegistrationMode;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\Doctrine\DoctrineUserLookup;
use Nowo\AuthKitBundle\User\UserLookupInterface;

/**
 * Determines whether registration is currently allowed: the open registration page ({@see self::isRegistrationAllowed()})
 * and the acceptance of an invitation ({@see self::isInvitationAcceptanceAllowed()}). `first_user_only` counts the users
 * through {@see UserLookupInterface} (1.41; default: the Doctrine repository).
 */
final class RegistrationGate
{
    private readonly UserLookupInterface $userLookup;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly ProfileRegistry $profileRegistry,
        ?UserLookupInterface $userLookup = null,
    ) {
        $this->userLookup = $userLookup ?? new DoctrineUserLookup($entityManager);
    }

    public function isRegistrationAllowed(?string $profileName = null): bool
    {
        $profile = $this->profile($profileName);

        $registrationMode = RegistrationMode::from($profile->registrationMode);

        return match ($registrationMode) {
            RegistrationMode::Disabled, RegistrationMode::InviteOnly => false,
            RegistrationMode::Always                                 => true,
            RegistrationMode::FirstUserOnly                          => $this->userLookup->countUsers($profile) === 0,
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
}
