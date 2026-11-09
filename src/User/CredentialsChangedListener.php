<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\User;

use Nowo\AuthKitBundle\Mfa\MfaManager;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenManagerInterface;
use Nowo\AuthKitBundle\Profile\ProfileRegistry;
use Nowo\AuthKitBundle\Profile\ProfileSettings;
use Nowo\AuthKitBundle\User\Event\CredentialsChangedEvent;

/**
 * Applies a host-dispatched {@see CredentialsChangedEvent} (1.41): MFA trusted devices forgotten when the password
 * changed (MFA profiles only), then the pending reset credential cleared (profiles with password reset on); users of no AuthKit profile are ignored.
 */
final readonly class CredentialsChangedListener
{
    public function __construct(
        private ProfileRegistry $profiles,
        private PasswordResetTokenManagerInterface $tokenManager,
        private ?MfaManager $mfaManager = null,
    ) {
    }

    public function __invoke(CredentialsChangedEvent $event): void
    {
        $profile = $this->profiles->resolveForObject($event->user);
        if (!$profile instanceof ProfileSettings) {
            return;
        }

        if ($event->passwordChanged) {
            $this->mfaManager?->forgetDevices($event->user, 'password_changed');
        }

        if (($profile->passwordReset['mode'] ?? 'disabled') !== 'disabled') {
            // @igor-ignore - Stateless call into the token store (writes the user's reset credential).
            $this->tokenManager->clearForUser($event->user);
        }
    }
}
