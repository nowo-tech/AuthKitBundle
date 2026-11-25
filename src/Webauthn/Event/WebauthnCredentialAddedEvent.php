<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Event;

/**
 * A passkey was registered (2.4.0). `synced`: the authenticator reported the credential as backed up (a synced passkey
 * such as iCloud Keychain or Google Password Manager).
 */
final class WebauthnCredentialAddedEvent extends WebauthnEvent
{
    public function __construct(
        string $profile,
        string $userType,
        string $userId,
        string $publicId,
        public readonly string $label,
        public readonly bool $synced,
    ) {
        parent::__construct($profile, $userType, $userId, $publicId);
    }
}
