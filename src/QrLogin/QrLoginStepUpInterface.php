<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * App-implemented step-up verification before QR login approval.
 *
 * Default NullQrLoginStepUp throws when approve_requires == session_step_up,
 * forcing apps to wire a real implementation (PIN, biometrics, WebAuthn).
 *
 * @deprecated since 1.46.0, implement {@see QrLoginStepUpPolicyInterface} (framework-free, REQ-ARCH-001 rule 3) instead; removed in 2.0.
 */
interface QrLoginStepUpInterface
{
    /**
     * @throws AccessDeniedException when unlock fails or is cancelled
     */
    public function assertUnlocked(Request $request): void;
}
