<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin\Event;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched after a QR login challenge is approved by the phone.
 */
final readonly class QrLoginApprovedEvent
{
    public function __construct(
        public QrLoginChallenge $challenge,
        public UserInterface $user,
        public string $profileName,
    ) {
    }
}
