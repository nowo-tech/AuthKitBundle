<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Default step-up that always denies — forces apps to wire a real implementation
 * when approve_requires == session_step_up.
 */
final class NullQrLoginStepUp implements QrLoginStepUpInterface
{
    public function assertUnlocked(Request $request): void
    {
        throw new AccessDeniedException('QR login step-up not implemented. Register a service implementing QrLoginStepUpInterface.');
    }
}
