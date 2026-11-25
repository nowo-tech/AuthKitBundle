<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\PersonalData;

use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Webauthn\WebauthnCredentialRecord;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_map;

/**
 * UserKit personal-data export section `passkeys` (2.4.0): for each passkey of the user (every profile) its label,
 * dates, transports, whether it is synced and the authenticator model id. Never the credential id, the public key,
 * the user handle or the counter. `null` when the user has none (or the table does not exist). Registered with the
 * eraser ({@see WebauthnPersonalDataEraser}).
 */
final readonly class WebauthnPersonalDataExportProvider implements PersonalDataExportProviderInterface
{
    public const SECTION = 'passkeys';

    /** After host sections, before UserKit's built-ins (-100 and below), like the MFA section. */
    public const PRIORITY = -50;

    public function __construct(
        private WebauthnStore $store,
        private MfaOwnerResolver $owners,
    ) {
    }

    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    public function supports(object $user): bool
    {
        return $user instanceof UserInterface;
    }

    public function export(object $user): mixed
    {
        $owner = $user instanceof UserInterface ? $this->owners->personalDataOwner($user) : null;
        if (!$owner instanceof MfaOwner || !$this->store->hasTable()) {
            return null;
        }
        $records = $this->store->allOf($owner->type, $owner->id);

        return $records === [] ? null : array_map(static fn (WebauthnCredentialRecord $record): array => [
            'profile'      => $record->profile,
            'label'        => $record->label,
            'created_at'   => $record->createdAt,
            'last_used_at' => $record->lastUsedAt,
            'transports'   => $record->transports,
            'synced'       => $record->backupState === true,
            'aaguid'       => $record->aaguid,
        ], $records);
    }
}
