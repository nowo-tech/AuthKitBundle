<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use DateTimeImmutable;

/**
 * Second-factor state of a user for pages and Twig ({@see MfaManager::status()}). Never carries the secret.
 *
 * @phpstan-type Device array{public_id: string, label: string, device_id: string|null, created_at: DateTimeImmutable, last_used_at: DateTimeImmutable, expires_at: DateTimeImmutable}
 */
final readonly class MfaStatus
{
    /**
     * @param list<Device> $trustedDevices
     */
    public function __construct(
        public string $profile,
        public bool $enabled,
        public ?DateTimeImmutable $enabledAt,
        public int $recoveryCodesLeft,
        public array $trustedDevices,
        public bool $required,
        public ?DateTimeImmutable $graceEndsAt,
        public int $lowRecoveryCodes,
    ) {
    }

    /**
     * Required, not enrolled, and still inside the grace period (pages show a reminder).
     */
    public function inGrace(): bool
    {
        return $this->required && !$this->enabled && $this->graceEndsAt instanceof DateTimeImmutable;
    }

    public function recoveryCodesLow(): bool
    {
        return $this->enabled && $this->recoveryCodesLeft <= $this->lowRecoveryCodes;
    }
}
