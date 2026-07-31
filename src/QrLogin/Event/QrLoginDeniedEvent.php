<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin\Event;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;

/**
 * Dispatched after a QR login challenge is denied by the phone.
 */
final readonly class QrLoginDeniedEvent
{
    public function __construct(
        public QrLoginChallenge $challenge,
        public string $profileName,
    ) {
    }
}
