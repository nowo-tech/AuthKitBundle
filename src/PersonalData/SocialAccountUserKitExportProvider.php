<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use InvalidArgumentException;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * UserKit personal-data export section `social_accounts` (1.45; registered with UserKit installed, the entities mapped
 * and `nowo_auth_kit.personal_data.enabled`, whatever `social_login.mode` says), beside the eraser
 * `auth_kit_social_account`: the links of the user (owner id of the profile's user class, plus links stored before
 * 1.37 under the identifier) from {@see SocialAccountPersonalDataExporterInterface}.
 */
final readonly class SocialAccountUserKitExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'social_accounts';

    public const PRIORITY = -50;

    public function __construct(
        private SocialAccountPersonalDataExporterInterface $exporter,
        private ProfileRegistry $profiles,
        private MfaOwnerResolver $owners,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface && $this->profiles->resolveForObject($user) instanceof ProfileSettings;
    }

    /**
     * @return list<array{provider: string, linked_at: string|null, provider_email: string|null, display_name: string|null}>
     */
    public function export(object $user): array
    {
        $profile = $user instanceof UserInterface ? $this->profiles->resolveForObject($user) : null;
        if (!$user instanceof UserInterface || !$profile instanceof ProfileSettings) {
            return [];
        }
        $owner      = $this->owners->resolve($user);
        $identifier = $user->getUserIdentifier();
        $subjects   = [];
        try {
            if ($owner instanceof MfaOwner) {
                $subjects[] = SocialAccountPersonalDataSubject::fromUserId($profile->userClass, $owner->id);
            }
        } catch (InvalidArgumentException) {
            // An id longer than the column: no link can hold it.
        }
        try {
            if (!$owner instanceof MfaOwner || $owner->id !== $identifier) {
                $subjects[] = SocialAccountPersonalDataSubject::fromLegacyIdentifier($profile->userClass, $identifier);
            }
        } catch (InvalidArgumentException) {
            // An empty or over-long identifier: no link can hold it.
        }
        $links = [];
        foreach ($subjects as $subject) {
            foreach ($this->exporter->export($subject) as $link) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
