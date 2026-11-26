<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Event;

use Nowo\AuthKitBundle\Webauthn\WebauthnManager;

/**
 * A passkey was renamed by its owner (2.5.0): `oldLabel` is the label the rename replaced (the stored row it matched,
 * so a concurrent rename never reports a label that was not there), `newLabel` the stored one (cleaned and capped, see
 * {@see WebauthnManager::label()}). Not dispatched when the label does not change. The
 * actor is the owner (`userType` / `userId`): renaming is self-service only (the account page refuses impersonation).
 */
final class WebauthnCredentialRenamedEvent extends WebauthnEvent
{
    public function __construct(
        string $profile,
        string $userType,
        string $userId,
        string $publicId,
        public readonly string $oldLabel,
        public readonly string $newLabel,
    ) {
        parent::__construct($profile, $userType, $userId, $publicId);
    }
}
