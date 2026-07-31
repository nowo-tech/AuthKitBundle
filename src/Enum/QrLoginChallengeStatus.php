<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Enum;

/**
 * Lifecycle states of a QR login challenge.
 */
enum QrLoginChallengeStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Denied   = 'denied';
    case Expired  = 'expired';
    case Consumed = 'consumed';
}
