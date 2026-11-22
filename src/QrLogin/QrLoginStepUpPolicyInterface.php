<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Step-up verification before a phone approves a QR login (1.46.0, `qr_login.approve_requires: session_step_up`): the
 * framework-free port (REQ-ARCH-001 rule 3; 2.0: replaces the removed `QrLoginStepUpInterface`). Alias this interface
 * to your service (PIN, biometrics, WebAuthn); the default ({@see NullQrLoginStepUp}) always refuses.
 *
 * Return true only when the approving user unlocked the step-up. False refuses the approval with a 403; any other
 * exception propagates (the approval fails). The Device Intelligence trusted-device check still runs before it when the profile enables it.
 * `$user` is the signed-in user of the approving phone (null when the token has none).
 */
interface QrLoginStepUpPolicyInterface
{
    public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool;
}
