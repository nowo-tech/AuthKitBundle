<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\PersonalData;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRemovedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_object_vars;
use function is_string;

/**
 * UserKit personal-data eraser `auth_kit_webauthn` (2.4.0): deletes the user's passkeys in every profile, by DBAL
 * statements (no flush; inside UserKit's erasure transaction). Registered whenever the entities are mapped and UserKit
 * is installed, also while no profile enables passkeys (a feature turned off later still erases, GY-L5); a missing
 * table is nothing to erase. Rows are found by the MFA owner references with the same trust rule for the identifier
 * captured when the erasure started as {@see MfaManager::eraseUser()}. One
 * {@see WebauthnCredentialRemovedEvent} (`reason: erasure`) per passkey after its statement.
 */
final readonly class WebauthnPersonalDataEraser implements PersonalDataEraserInterface
{
    public const KEY = 'auth_kit_webauthn';

    public const PRIORITY = 200;

    public function __construct(
        private WebauthnStore $store,
        private MfaOwnerResolver $owners,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface;
    }

    public function erase(ErasureRequest $request): void
    {
        if (!$request->user instanceof UserInterface || !$this->store->hasTable()) {
            return;
        }
        $original = get_object_vars($request)['originalIdentifier'] ?? null;
        foreach ($this->owners->erasureOwners($request->user, is_string($original) ? $original : null) as $owner) {
            foreach ($this->store->allOf($owner->type, $owner->id) as $record) {
                if ($this->store->delete($record)) {
                    $this->dispatcher?->dispatch(new WebauthnCredentialRemovedEvent($record->profile, $record->userType, $record->userId, $record->publicId, $record->label, WebauthnCredentialRemovedEvent::REASON_ERASURE));
                }
            }
        }
    }
}
