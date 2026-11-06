<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\MultiAccount\PersonalData\AccountSwitcherPersonalDataEraser;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_unique;
use function get_object_vars;
use function is_string;

/**
 * `auth_kit_account_switcher` while no profile enables the switcher (1.37, GY-L5). Registered instead of
 * {@see AccountSwitcherPersonalDataEraser}, with the same key and priority. Tolerates a missing table.
 */
final readonly class DormantAccountSwitcherPersonalDataEraser implements PersonalDataEraserInterface
{
    public function __construct(
        private AccountVaultStore $store,
        private ProfileRegistry $profiles,
    ) {
    }

    public function getKey(): string
    {
        return AccountSwitcherPersonalDataEraser::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface && $this->profiles->resolveForObject($user) instanceof ProfileSettings;
    }

    public function erase(ErasureRequest $request): void
    {
        $user    = $request->user;
        $profile = $user instanceof UserInterface ? $this->profiles->resolveForObject($user) : null;
        if (!$user instanceof UserInterface || !$profile instanceof ProfileSettings || !$this->store->hasTable()) {
            return;
        }
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        foreach (array_unique([is_string($original) && $original !== '' ? $original : $user->getUserIdentifier(), $user->getUserIdentifier()]) as $identifier) {
            $this->store->deleteForUser($profile->name, $identifier);
        }
    }
}
