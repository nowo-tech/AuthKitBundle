<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaOwnerResolver;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRemovedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Since 2.4.1 (audit ZBX-v-I2): deletes every passkey of a user, in every profile, **whatever the passkey mode is
 * today**. {@see MfaManager::forgetUser()} uses it, so a host that turned passkeys off in every profile (no
 * {@see WebauthnManager} then) and deletes users without UserKit still removes the stored rows; a passkey left behind
 * would sign in a later account that gets the same id once passkeys are on again. {@see WebauthnManager::forgetUser()}
 * delegates here too. No mapped entity or no table: nothing to erase. One {@see WebauthnCredentialRemovedEvent}
 * (`reason: erasure`) per deleted passkey. Stateless (FrankenPHP worker mode).
 */
final readonly class WebauthnErasure
{
    public function __construct(
        private WebauthnStore $store,
        private MfaOwnerResolver $owners,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Deletes the user's passkeys and returns how many were deleted.
     */
    public function forgetUser(UserInterface $user): int
    {
        $owner = $this->owners->personalDataOwner($user);
        if (!$owner instanceof MfaOwner || !$this->store->isMapped() || !$this->store->hasTable()) {
            return 0;
        }
        $deleted = 0;
        foreach ($this->store->allOf($owner->type, $owner->id) as $record) {
            if ($this->store->delete($record)) {
                ++$deleted;
                $this->dispatcher?->dispatch(new WebauthnCredentialRemovedEvent($record->profile, $record->userType, $record->userId, $record->publicId, $record->label, WebauthnCredentialRemovedEvent::REASON_ERASURE));
            }
        }

        return $deleted;
    }
}
