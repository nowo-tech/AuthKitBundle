<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use DateTimeImmutable;

/**
 * One account of the browser's vault (1.34), resolved from a cookie token: the stored row plus the raw token that
 * matched it (needed to unseal and rotate). Never rendered, logged or dispatched with the token.
 */
final readonly class VaultEntry
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $userIdentifier,
        public string $token,
        public string $tokenHash,
        public ?string $sealed,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
