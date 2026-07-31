<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin\Event;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Dispatched after desktop completes QR login (Security::login executed).
 */
final readonly class QrLoginCompletedEvent
{
    public function __construct(
        public QrLoginChallenge $challenge,
        public UserInterface $user,
        public string $profileName,
    ) {
    }
}
