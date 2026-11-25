<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use DateTimeImmutable;

/**
 * One stored passkey as read by {@see WebauthnStore} (2.4.0): a detached value, never a managed entity (worker safe).
 * Binary values are unpadded base64url. Internal: hosts see {@see WebauthnPasskey}.
 *
 * @internal
 */
final readonly class WebauthnCredentialRecord
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public string $profile,
        public string $userType,
        public string $userId,
        public string $credentialId,
        public string $userHandle,
        public string $publicKey,
        public int $signCount,
        public array $transports,
        public ?string $aaguid,
        public ?bool $backupEligible,
        public ?bool $backupState,
        public string $label,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {
    }

    public function passkey(): WebauthnPasskey
    {
        return new WebauthnPasskey($this->publicId, $this->label, $this->createdAt, $this->lastUsedAt, $this->transports, $this->backupState === true, $this->aaguid);
    }
}
