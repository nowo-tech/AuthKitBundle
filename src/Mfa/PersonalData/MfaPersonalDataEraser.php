<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa\PersonalData;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_object_vars;
use function is_string;

/**
 * UserKit personal-data eraser `auth_kit_mfa` (1.36, DATA-AUTHKIT-MFA-ERASE-EXPORT; registered with UserKit installed
 * and a profile with `mfa.enabled`): deletes the user's TOTP secret, recovery codes and trusted devices. Tagged with
 * priority {@see self::PRIORITY}, before host erasers (identity anonymisation). DBAL deletes only, no flush.
 *
 * Rows are found by the user's owner reference (class and id) in every profile ({@see MfaManager::eraseUser()}). When
 * the id is the user identifier (e-mail natural key, non-Doctrine user) and a host identity eraser ran first, the
 * identifier captured when the erasure started (`ErasureRequest::identifier()`, UserKit ≥ 1.12) is looked up too; with
 * older UserKit only the current one is (enough while host identity erasers stay below {@see self::PRIORITY}).
 * Supports users of an MFA-enabled profile and users with MFA rows left in another profile.
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
        return $user instanceof UserInterface
            && ($this->manager->profileFor($user) instanceof ProfileSettings || $this->manager->hasStoredCredentials($user));
    }

    public function erase(ErasureRequest $request): void
    {
        if ($request->user instanceof UserInterface) {
            // UserKit >= 1.12 carries the identifier captured before any eraser ran (read as a property, so this also
            // works against UserKit 1.11, where it is absent).
            $original = get_object_vars($request)['originalIdentifier'] ?? null;
            $this->manager->eraseUser($request->user, is_string($original) ? $original : null);
        }
    }
}
