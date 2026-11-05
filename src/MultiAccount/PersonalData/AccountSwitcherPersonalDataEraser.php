<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount\PersonalData;

use Nowo\AuthKitBundle\MultiAccount\AccountVault;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_unique;
use function get_object_vars;
use function is_string;

/**
 * UserKit personal-data eraser `auth_kit_account_switcher` (1.36, DATA-AUTHKIT-VAULT-ERASE; registered with UserKit
 * installed and a profile with `multi_account.enabled`): removes the user from every browser's account switcher.
 *
 * The vault is keyed by `getUserIdentifier()` (the e-mail in most hosts), which a host identity eraser anonymises. This
 * eraser therefore runs at priority {@see self::PRIORITY}, before host erasers, and deletes by the identifier captured
 * when the erasure started (`ErasureRequest::identifier()`, UserKit ≥ 1.12) as well as the current one.
 */
final readonly class AccountSwitcherPersonalDataEraser implements PersonalDataEraserInterface
{
    public const KEY = 'auth_kit_account_switcher';

    public const PRIORITY = 200;

    public function __construct(
        private AccountVault $vault,
        private ProfileRegistry $profiles,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface && $this->profileOf($user) instanceof ProfileSettings;
    }

    public function erase(ErasureRequest $request): void
    {
        $user    = $request->user;
        $profile = $user instanceof UserInterface ? $this->profileOf($user) : null;
        if (!$user instanceof UserInterface || !$profile instanceof ProfileSettings) {
            return;
        }
        // UserKit >= 1.12 carries the identifier captured before any eraser ran; older versions do not, but at this
        // priority the current identifier is still the original one.
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        foreach (array_unique([is_string($original) && $original !== '' ? $original : $user->getUserIdentifier(), $user->getUserIdentifier()]) as $identifier) {
            $this->vault->forgetUser($profile, $identifier);
        }
    }

    private function profileOf(UserInterface $user): ?ProfileSettings
    {
        $profile = $this->profiles->resolveForObject($user);

        return $profile?->multiAccountEnabled() === true ? $profile : null;
    }
}
