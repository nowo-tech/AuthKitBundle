<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\PersonalData;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * UserKit personal-data export section `mfa` (1.36, DATA-AUTHKIT-MFA-ERASE-EXPORT; registered with UserKit installed
 * and a profile with `mfa.enabled`): {@see MfaManager::exportUser()} — enrolment date, method, number of unused
 * recovery codes and trusted devices (label, created, last used, expiry). Never the TOTP secret, the recovery codes
 * or the device tokens. `null` for a user who never enrolled.
 */
final readonly class MfaPersonalDataExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'mfa';

    /** After host sections, before UserKit's built-ins (-100 and below). */
    public const PRIORITY = -50;

    public function __construct(
        private MfaManager $manager,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface && $this->manager->profileFor($user) instanceof ProfileSettings;
    }

    public function export(object $user): mixed
    {
        return $user instanceof UserInterface ? $this->manager->exportUser($user) : null;
    }
}
