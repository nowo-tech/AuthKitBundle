<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Nowo\AuthKitBundle\ValueObject\AuthRequestContext;
use Nowo\AuthKitBundle\ValueObject\AuthUserRef;

/**
 * Default step-up that always refuses: forces apps to alias {@see QrLoginStepUpPolicyInterface} to a real policy (PIN,
 * biometrics, WebAuthn) when approve_requires == session_step_up. The approval answers 403.
 *
 * 2.0: on the port (it implemented the removed QrLoginStepUpInterface and threw `AccessDeniedException`; the gate now
 * throws it for the refusal, so the answer is the same).
 */
final class NullQrLoginStepUp implements QrLoginStepUpPolicyInterface
{
    public function isUnlocked(AuthRequestContext $request, ?AuthUserRef $user): bool
    {
        return false;
    }
}
