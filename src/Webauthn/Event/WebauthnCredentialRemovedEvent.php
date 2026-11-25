<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Event;

/**
 * A passkey was removed (2.4.0): by its owner (`reason: user`) or by a personal-data erasure (`reason: erasure`, one
 * event per passkey, dispatched after the eraser's statements; the erasure transaction may still roll back).
 */
final class WebauthnCredentialRemovedEvent extends WebauthnEvent
{
    public const REASON_USER = 'user';

    public const REASON_ERASURE = 'erasure';

    public function __construct(
        string $profile,
        string $userType,
        string $userId,
        string $publicId,
        public readonly string $label,
        public readonly string $reason,
    ) {
        parent::__construct($profile, $userType, $userId, $publicId);
    }
}
