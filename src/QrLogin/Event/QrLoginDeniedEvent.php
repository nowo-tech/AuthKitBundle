<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\QrLogin\Event;

use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\ValueObject\QrLoginChallengeRef;

/**
 * Dispatched after a QR login challenge is denied by the phone.
 */
final readonly class QrLoginDeniedEvent
{
    /**
     * @param QrLoginChallenge $challenge not public since 2.0: read challengeRef() or challengeId()
     */
    public function __construct(
        private QrLoginChallenge $challenge,
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

    /**
     * The `$profileName` value (1.55.0): read it here; same value as the property.
     */
    public function profileName(): string
    {
        return $this->profileName;
    }
}
