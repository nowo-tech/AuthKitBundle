<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * How the phone proves the user during QR login approval.
 */
enum QrLoginApproveMode: string
{
    case Session       = 'session';
    case SessionStepUp = 'session_step_up';
}
