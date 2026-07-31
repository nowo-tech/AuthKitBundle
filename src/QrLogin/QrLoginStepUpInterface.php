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
 */
interface QrLoginStepUpInterface
{
    /**
     * @throws AccessDeniedException when unlock fails or is cancelled
     */
    public function assertUnlocked(Request $request): void;
}
