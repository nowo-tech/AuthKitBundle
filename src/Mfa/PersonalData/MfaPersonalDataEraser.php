<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\PersonalData;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * UserKit personal-data eraser `auth_kit_mfa` (1.36, DATA-AUTHKIT-MFA-ERASE-EXPORT; registered with UserKit installed
 * and a profile with `mfa.enabled`): deletes the user's TOTP secret, recovery codes and trusted devices. Tagged with
 * priority {@see self::PRIORITY}, before host erasers (identity anonymisation): the rows are found by the user's
 * owner reference (class and id), which an anonymised e-mail does not change. DBAL deletes only, no flush.
 */
final readonly class MfaPersonalDataEraser implements PersonalDataEraserInterface
{
    public const KEY = 'auth_kit_mfa';

    public const PRIORITY = 200;

    public function __construct(
        private MfaManager $manager,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface && $this->manager->profileFor($user) instanceof ProfileSettings;
    }

    public function erase(ErasureRequest $request): void
    {
        if ($request->user instanceof UserInterface) {
            $this->manager->eraseUser($request->user);
        }
    }
}
