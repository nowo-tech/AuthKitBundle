<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Step-up verification before a phone approves a QR login (1.46.0, `qr_login.approve_requires: session_step_up`): the
 * framework-free twin of the deprecated {@see QrLoginStepUpInterface} (REQ-ARCH-001 rule 3). Alias this interface to
 * your service (PIN, biometrics, WebAuthn) instead of the deprecated one.
 *
 * Return true only when the approving user unlocked the step-up. False refuses the approval with a 403, exactly like
 * the deprecated interface's `AccessDeniedException`; any other exception propagates (the approval fails), as with the
 * deprecated interface. The Device Intelligence trusted-device check still runs before it when the profile enables it.
 * `$user` is the signed-in user of the approving phone (null when the token has none).
 */
interface QrLoginStepUpPolicyInterface
{
    public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool;
}
