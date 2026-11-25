<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use DateTimeImmutable;

/**
 * What a host or a template may show of a passkey (2.4.0): its public id (rename / delete routes), label, dates,
 * transports, whether it is synced (backed up) and the authenticator model id (AAGUID, all zeros with attestation
 * `none` on most platforms). Never the credential id, the public key, the user handle or the counter.
 */
final readonly class WebauthnPasskey
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $publicId,
        public string $label,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
        public array $transports,
        public bool $synced,
        public ?string $aaguid,
    ) {
    }
}
