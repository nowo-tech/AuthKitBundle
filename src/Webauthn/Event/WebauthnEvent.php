<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Event;

/**
 * Base of the passkey audit events (2.4.0). Framework-free (ARCH-001 rule 3): the profile, the owner reference (the
 * MFA owner key halves: Doctrine root class or user class, and the identifier) and the passkey's public id. Never the
 * credential id, the public key or the user handle. Dispatched after the write.
 */
abstract class WebauthnEvent
{
    public function __construct(
        public readonly string $profile,
        public readonly string $userType,
        public readonly string $userId,
        public readonly string $publicId,
    ) {
    }
}
