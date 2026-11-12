<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin\Event;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\ValueObject\QrLoginChallengeRef;

/**
 * Dispatched after a QR login challenge is created.
 */
final readonly class QrLoginChallengeCreatedEvent
{
    /**
     * @param QrLoginChallenge $challenge @deprecated since 1.46.0, use challengeRef() or challengeId(); removed in 2.0
     */
    public function __construct(
        public QrLoginChallenge $challenge,
        public string $profileName,
    ) {
    }

    /**
     * The challenge id (1.46.0, REQ-ARCH-001 rule 4).
     */
    public function challengeId(): string
    {
        return $this->challenge->getId();
    }

    /**
     * The challenge, framework-free and without secrets (1.46.0).
     */
    public function challengeRef(): QrLoginChallengeRef
    {
        return QrLoginChallengeRef::fromChallenge($this->challenge);
    }
}
