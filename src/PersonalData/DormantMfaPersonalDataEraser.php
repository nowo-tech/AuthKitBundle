<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\Mfa\PersonalData\MfaPersonalDataEraser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_object_vars;
use function is_string;

/**
 * `auth_kit_mfa` while no profile enables MFA (1.37, GY-L5): the rows of a feature turned off later still go on erasure.
 * Registered instead of {@see MfaPersonalDataEraser}, with the same key and priority. Tolerates missing tables.
 */
final readonly class DormantMfaPersonalDataEraser implements PersonalDataEraserInterface
{
    public function __construct(
        private MfaStore $store,
        private MfaOwnerResolver $owners,
    ) {
    }

    public function getKey(): string
    {
        return MfaPersonalDataEraser::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface;
    }

    public function erase(ErasureRequest $request): void
    {
        if (!$request->user instanceof UserInterface || !$this->store->hasTables()) {
            return;
        }
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        foreach ($this->owners->erasureOwners($request->user, is_string($original) ? $original : null) as $owner) {
            foreach ($this->store->credentialsOf($owner) as $credential) {
                $this->store->deleteWithoutFlush($credential);
            }
        }
    }
}
